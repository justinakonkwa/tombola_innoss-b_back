<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\AuditAction;
use App\Enums\TicketStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\TicketResource;
use App\Models\Campaign;
use App\Models\Ticket;
use App\Models\TicketBatch;
use App\Services\AuditService;
use App\Services\TicketService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Gestion des tickets au back-office (cahier des charges §7, §47).
 *
 * Un ticket n'est jamais supprimé : il est annulé, avec un motif conservé en
 * base et une entrée d'audit. L'annulation d'un lot passe par le TicketService
 * (qui ajuste le compteur de ventes) ; l'annulation unitaire, elle, ne touche
 * pas au compteur global.
 */
class TicketController extends Controller
{
    private const SORTABLE = ['created_at', 'issued_at', 'serial', 'status'];

    public function __construct(
        private readonly TicketService $tickets,
        private readonly AuditService $audit,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'search' => ['nullable', 'string', 'max:64'],
            'status' => ['nullable', Rule::enum(TicketStatus::class)],
            'campaign' => ['nullable', 'string', 'max:180'],
            'user_id' => ['nullable', 'uuid'],
            'batch_id' => ['nullable', 'uuid'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'sort' => ['nullable', 'string', 'max:32'],
        ]);

        $query = Ticket::query()
            ->with(['user', 'campaign', 'batch', 'order', 'prize'])
            ->when($request->filled('search'), function (Builder $query) use ($request) {
                $term = '%'.mb_strtolower((string) $request->string('search')).'%';

                $query->where(fn (Builder $q) => $q
                    ->whereRaw('LOWER(ticket_number) LIKE ?', [$term])
                    ->orWhereHas('order', fn (Builder $o) => $o->whereRaw('LOWER(reference) LIKE ?', [$term])));
            })
            ->when($request->filled('status'), fn (Builder $q) => $q->where('status', (string) $request->string('status')))
            ->when($request->filled('campaign'), function (Builder $query) use ($request) {
                $value = (string) $request->string('campaign');

                $query->whereHas('campaign', fn (Builder $q) => $q->where(
                    Str::isUuid($value) ? 'id' : 'slug',
                    $value
                ));
            })
            ->when($request->filled('user_id'), fn (Builder $q) => $q->where('user_id', (string) $request->string('user_id')))
            ->when($request->filled('batch_id'), fn (Builder $q) => $q->where('batch_id', (string) $request->string('batch_id')))
            ->when($request->filled('from'), fn (Builder $q) => $q->where('created_at', '>=', $request->date('from')))
            ->when($request->filled('to'), fn (Builder $q) => $q->where('created_at', '<=', $request->date('to')->endOfDay()));

        $this->applySort($query, $request);

        return TicketResource::collection($query->paginate($this->perPage($request)))->response();
    }

    public function show(Ticket $ticket): JsonResponse
    {
        $ticket->load(['user', 'campaign', 'batch', 'order', 'prize']);

        return response()->json(['data' => new TicketResource($ticket)]);
    }

    /** Annule un ticket valide (fraude, remboursement, erreur de saisie). */
    public function cancel(Request $request, Ticket $ticket): JsonResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'min:3', 'max:255'],
        ]);

        // Seul un ticket encore valide peut être annulé : un ticket déjà utilisé
        // ou gagnant relève d'une procédure (remboursement, litige).
        if ($ticket->status !== TicketStatus::Valid) {
            return response()->json([
                'message' => 'Seul un ticket valide peut être annulé.',
                'status' => $ticket->status->value,
            ], 409);
        }

        $before = ['status' => $ticket->status->value, 'is_locked' => (bool) $ticket->is_locked];

        $ticket->forceFill([
            'status' => TicketStatus::Cancelled,
            'cancelled_at' => now(),
            'cancellation_reason' => $data['reason'],
            'is_locked' => false,
        ])->save();

        // Annulation unitaire : le compteur de campagne n'est pas ajusté ici
        // (cf. TicketService::cancelBatch pour l'annulation d'un lot complet).
        $this->audit->log(AuditAction::TicketCancelled, $ticket, $before, [
            'status' => $ticket->status->value,
            'reason' => $data['reason'],
        ], $request->user());

        return response()->json([
            'message' => 'Ticket annulé.',
            'data' => new TicketResource($ticket->refresh()->load(['user', 'campaign', 'order', 'prize'])),
        ]);
    }

    /** Annule tous les tickets encore actifs d'un lot (et ajuste le compteur de ventes). */
    public function cancelBatch(Request $request, TicketBatch $batch): JsonResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'min:3', 'max:255'],
        ]);

        $cancelled = $this->tickets->cancelBatch($batch, $data['reason']);

        return response()->json([
            'message' => 'Lot annulé.',
            'data' => [
                'batch_id' => $batch->id,
                'cancelled' => $cancelled,
                'reason' => $data['reason'],
                'campaign' => Campaign::query()->whereKey($batch->campaign_id)->value('name'),
            ],
        ]);
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
