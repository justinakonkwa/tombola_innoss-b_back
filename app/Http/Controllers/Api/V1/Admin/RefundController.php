<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\AuditAction;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Models\Refund;
use App\Services\AuditService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Remboursements (cahier des charges §26).
 *
 * Un remboursement ne peut porter que sur un paiement encaissé, jamais au-delà
 * du montant payé, et un seul dossier actif à la fois par paiement. La demande
 * et la décision sont tracées séparément dans le journal d'audit.
 */
class RefundController extends Controller
{
    /** Statuts d'un dossier encore ouvert. */
    private const OPEN_STATUSES = ['requested', 'approved', 'processing'];

    /** Statuts admis par la contrainte PostgreSQL `refunds_status_check`. */
    private const STATUSES = ['requested', 'approved', 'processing', 'completed', 'rejected'];

    private const SORTABLE = ['created_at', 'amount', 'processed_at', 'status'];

    public function __construct(private readonly AuditService $audit)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'status' => ['nullable', Rule::in(self::STATUSES)],
            'payment_id' => ['nullable', 'uuid'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'sort' => ['nullable', 'string', 'max:32'],
        ]);

        $query = Refund::query()
            ->with(['payment', 'order', 'requester', 'approver'])
            ->when($request->filled('status'), fn (Builder $q) => $q->where('status', (string) $request->string('status')))
            ->when($request->filled('payment_id'), fn (Builder $q) => $q->where('payment_id', (string) $request->string('payment_id')))
            ->when($request->filled('from'), fn (Builder $q) => $q->where('created_at', '>=', $request->date('from')))
            ->when($request->filled('to'), fn (Builder $q) => $q->where('created_at', '<=', $request->date('to')->endOfDay()));

        $this->applySort($query, $request);

        $refunds = $query->paginate($this->perPage($request));

        return response()->json([
            'data' => collect($refunds->items())->map(fn (Refund $refund) => $this->presentRefund($refund))->all(),
            'meta' => $this->paginationMeta($refunds),
            'links' => $this->paginationLinks($refunds),
        ]);
    }

    /** Ouvre une demande de remboursement sur un paiement encaissé. */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'payment_id' => ['required', 'uuid', 'exists:payments,id'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        $payment = Payment::query()->with('order')->findOrFail($data['payment_id']);

        if ($payment->status !== PaymentStatus::Success) {
            throw ValidationException::withMessages([
                'payment_id' => 'Seul un paiement encaissé peut faire l’objet d’un remboursement.',
            ]);
        }

        if ((float) $data['amount'] > (float) $payment->amount) {
            throw ValidationException::withMessages([
                'amount' => 'Le montant remboursé ne peut pas dépasser le montant du paiement.',
            ]);
        }

        // Un seul dossier ouvert par paiement : évite les doubles remboursements.
        $alreadyOpen = Refund::query()
            ->where('payment_id', $payment->id)
            ->whereIn('status', self::OPEN_STATUSES)
            ->exists();

        if ($alreadyOpen) {
            return response()->json([
                'message' => 'Un remboursement est déjà en cours pour ce paiement.',
            ], 409);
        }

        $refund = Refund::query()->create([
            'payment_id' => $payment->id,
            'order_id' => $payment->order_id,
            'amount' => $data['amount'],
            'currency' => $payment->currency,
            'reason' => $data['reason'] ?? null,
            'status' => 'requested',
            'requested_by' => $request->user()->id,
        ]);

        $this->audit->log(AuditAction::RefundRequested, $refund, [], [
            'payment_id' => $payment->id,
            'order_reference' => $payment->order?->reference,
            'amount' => (string) $refund->amount,
            'currency' => $refund->currency,
            'reason' => $refund->reason,
        ], $request->user());

        return response()->json([
            'message' => 'Demande de remboursement enregistrée.',
            'data' => $this->presentRefund($refund->load(['payment', 'order', 'requester'])),
        ], 201);
    }

    /** Approuve et exécute le remboursement (opération manuelle tracée). */
    /**
     * Existe-t-il un autre membre du personnel habilité à approuver un
     * remboursement ? Sans cela, exiger un second approbateur rendrait tout
     * remboursement impossible dans une équipe d'une seule personne.
     */
    private function anotherApproverExists(\App\Models\User $current): bool
    {
        return \App\Models\User::query()
            ->whereKeyNot($current->id)
            ->where('status', 'active')
            ->where(function ($query) {
                $query->where('is_admin', true)
                    ->orWhereHas('roles', function ($roleQuery) {
                        $roleQuery->whereIn('name', [
                            \App\Enums\RoleName::SuperAdmin->value,
                            \App\Enums\RoleName::Finance->value,
                        ]);
                    });
            })
            ->exists();
    }

    public function approve(Request $request, Refund $refund): JsonResponse
    {
        $data = $request->validate([
            'provider_refund_id' => ['nullable', 'string', 'max:128'],
        ]);

        if (! in_array($refund->status, self::OPEN_STATUSES, true)) {
            return response()->json([
                'message' => 'Ce remboursement a déjà été traité.',
                'status' => $refund->status,
            ], 409);
        }

        // Séparation des tâches (principe des quatre yeux, cahier des charges
        // §15 et §27) : l'auteur d'une demande ne doit pas l'approuver lui-même.
        // La règle ne s'applique que s'il existe un autre approbateur : une
        // équipe réduite ne doit pas se retrouver dans l'impossibilité de
        // traiter un remboursement.
        if ($refund->requested_by !== null
            && $refund->requested_by === $request->user()->id
            && $this->anotherApproverExists($request->user())) {
            throw ValidationException::withMessages([
                'refund' => 'Un remboursement doit être approuvé par une autre personne que son auteur.',
            ]);
        }

        $payment = $refund->payment()->with('order')->firstOrFail();
        $before = ['status' => $refund->status];

        DB::transaction(function () use ($refund, $payment, $data, $before, $request) {
            $refund->forceFill([
                'status' => 'completed',
                'approved_by' => $request->user()->id,
                'processed_at' => now(),
                'provider_refund_id' => $data['provider_refund_id'] ?? $refund->provider_refund_id,
            ])->save();

            // Décision puis exécution : deux entrées d'audit distinctes.
            $this->audit->log(AuditAction::RefundApproved, $refund, $before, [
                'status' => $refund->status,
                'approved_by' => $request->user()->id,
            ], $request->user());

            // Un remboursement total bascule le paiement et la commande en « remboursé ».
            if ((float) $refund->amount >= (float) $payment->amount) {
                $payment->forceFill(['status' => PaymentStatus::Refunded])->save();
                $payment->order?->forceFill(['status' => OrderStatus::Refunded])->save();
            }

            $this->audit->log(AuditAction::RefundCompleted, $refund, [], [
                'amount' => (string) $refund->amount,
                'currency' => $refund->currency,
                'payment_id' => $payment->id,
                'full_refund' => (float) $refund->amount >= (float) $payment->amount,
            ], $request->user());
        });

        return response()->json([
            'message' => 'Remboursement approuvé et traité.',
            'data' => $this->presentRefund($refund->refresh()->load(['payment', 'order', 'requester', 'approver'])),
        ]);
    }

    /** Rejette la demande : le paiement reste inchangé. */
    public function reject(Request $request, Refund $refund): JsonResponse
    {
        $data = $request->validate([
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        if (! in_array($refund->status, self::OPEN_STATUSES, true)) {
            return response()->json([
                'message' => 'Ce remboursement a déjà été traité.',
                'status' => $refund->status,
            ], 409);
        }

        $before = ['status' => $refund->status];

        $refund->forceFill([
            'status' => 'rejected',
            'approved_by' => $request->user()->id,
            'processed_at' => now(),
            'reason' => $data['reason'] ?? $refund->reason,
        ])->save();

        // Aucun cas « RefundRejected » dans l'enum : la décision est journalisée
        // sous le cas de décision existant, avec `approved = false`.
        $this->audit->log(AuditAction::RefundApproved, $refund, $before, [
            'status' => $refund->status,
            'approved' => false,
            'reason' => $refund->reason,
        ], $request->user());

        return response()->json([
            'message' => 'Remboursement rejeté.',
            'data' => $this->presentRefund($refund->load(['payment', 'order', 'requester', 'approver'])),
        ]);
    }

    // ------------------------------------------------------------------ helpers

    /**
     * @return array<string, mixed>
     */
    private function presentRefund(Refund $refund): array
    {
        return [
            'id' => $refund->id,
            'payment_id' => $refund->payment_id,
            'order_id' => $refund->order_id,
            'order_reference' => $refund->order?->reference,
            'payment_reference' => $refund->payment?->provider_reference,
            'amount' => (float) $refund->amount,
            'currency' => $refund->currency,
            'reason' => $refund->reason,
            'status' => $refund->status,
            'provider_refund_id' => $refund->provider_refund_id,
            'requested_by' => $refund->requester?->fullName(),
            'approved_by' => $refund->approver?->fullName(),
            'processed_at' => $refund->processed_at?->toIso8601String(),
            'created_at' => $refund->created_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function paginationMeta(LengthAwarePaginator $paginator): array
    {
        return [
            'current_page' => $paginator->currentPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
            'last_page' => $paginator->lastPage(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function paginationLinks(LengthAwarePaginator $paginator): array
    {
        return [
            'first' => $paginator->url(1),
            'prev' => $paginator->previousPageUrl(),
            'next' => $paginator->nextPageUrl(),
            'last' => $paginator->url($paginator->lastPage()),
        ];
    }

    private function perPage(Request $request): int
    {
        return max(1, min((int) $request->integer('per_page', 25), 100));
    }

    /**
     * @param  array<int, string>  $allowed
     */
    private function applySort(Builder $query, Request $request, array $allowed = self::SORTABLE, string $default = '-created_at'): void
    {
        $sort = (string) $request->string('sort', $default);
        $column = ltrim($sort, '-');

        if (! in_array($column, $allowed, true)) {
            $column = ltrim($default, '-');
            $sort = $default;
        }

        $query->orderBy($column, str_starts_with($sort, '-') ? 'desc' : 'asc');
    }
}
