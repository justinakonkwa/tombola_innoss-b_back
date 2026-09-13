<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\AuditAction;
use App\Enums\PaymentChannel;
use App\Enums\PaymentStatus;
use App\Enums\WebhookStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\PaymentResource;
use App\Models\Payment;
use App\Models\PaymentWebhook;
use App\Services\AuditService;
use App\Services\PaymentService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Supervision des paiements (cahier des charges §11, §26, §38).
 *
 * Le back-office ne peut pas forcer un paiement : il ne peut que demander une
 * réconciliation à la passerelle, qui applique le statut réel.
 */
class PaymentController extends Controller
{
    private const SORTABLE = ['created_at', 'amount', 'confirmed_at', 'status'];

    public function __construct(
        private readonly PaymentService $payments,
        private readonly AuditService $audit,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'search' => ['nullable', 'string', 'max:180'],
            'status' => ['nullable', Rule::enum(PaymentStatus::class)],
            'channel' => ['nullable', Rule::enum(PaymentChannel::class)],
            'currency' => ['nullable', 'string', 'size:3'],
            'campaign' => ['nullable', 'string', 'max:180'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'sort' => ['nullable', 'string', 'max:32'],
        ]);

        $query = Payment::query()
            ->with(['order.campaign', 'order.user'])
            ->when($request->filled('search'), function (Builder $query) use ($request) {
                $term = '%'.mb_strtolower((string) $request->string('search')).'%';

                $query->where(fn (Builder $q) => $q
                    ->whereRaw("LOWER(COALESCE(provider_reference, '')) LIKE ?", [$term])
                    ->orWhereRaw("LOWER(COALESCE(provider_payment_id, '')) LIKE ?", [$term])
                    ->orWhereHas('order', fn (Builder $o) => $o->whereRaw('LOWER(reference) LIKE ?', [$term])));
            })
            ->when($request->filled('status'), fn (Builder $q) => $q->where('status', (string) $request->string('status')))
            ->when($request->filled('channel'), fn (Builder $q) => $q->where('channel', (string) $request->string('channel')))
            ->when($request->filled('currency'), fn (Builder $q) => $q->where('currency', strtoupper((string) $request->string('currency'))))
            ->when($request->filled('campaign'), fn (Builder $q) => $this->scopeCampaign($q, (string) $request->string('campaign')))
            ->when($request->filled('from'), fn (Builder $q) => $q->where('created_at', '>=', $request->date('from')))
            ->when($request->filled('to'), fn (Builder $q) => $q->where('created_at', '<=', $request->date('to')->endOfDay()));

        $this->applySort($query, $request);

        return PaymentResource::collection($query->paginate($this->perPage($request)))->response();
    }

    /** Synthèse financière filtrable : totaux, répartition par statut et par canal. */
    public function summary(Request $request): JsonResponse
    {
        $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'status' => ['nullable', Rule::enum(PaymentStatus::class)],
            'channel' => ['nullable', Rule::enum(PaymentChannel::class)],
            'campaign' => ['nullable', 'string', 'max:180'],
            'currency' => ['nullable', 'string', 'size:3'],
        ]);

        $totals = $this->summaryQuery($request)
            ->selectRaw('COUNT(*) AS count, COALESCE(SUM(payments.amount), 0) AS amount, COALESCE(SUM(payments.commission), 0) AS commission, COALESCE(SUM(payments.net), 0) AS net')
            ->first();

        $byStatus = $this->summaryQuery($request)
            ->selectRaw('payments.status AS status, COUNT(*) AS count, COALESCE(SUM(payments.amount), 0) AS amount')
            ->groupBy('payments.status')
            ->orderBy('payments.status')
            ->get()
            ->map(fn ($row) => [
                'status' => (string) $row->status,
                'status_label' => PaymentStatus::tryFrom((string) $row->status)?->label(),
                'count' => (int) $row->count,
                'amount' => round((float) $row->amount, 2),
            ])
            ->values()
            ->all();

        $byChannel = $this->summaryQuery($request)
            ->selectRaw('payments.channel AS channel, COUNT(*) AS count, COALESCE(SUM(payments.amount), 0) AS amount')
            ->groupBy('payments.channel')
            ->orderBy('payments.channel')
            ->get()
            ->map(fn ($row) => [
                'channel' => (string) $row->channel,
                'channel_label' => PaymentChannel::tryFrom((string) $row->channel)?->label(),
                'count' => (int) $row->count,
                'amount' => round((float) $row->amount, 2),
            ])
            ->values()
            ->all();

        return response()->json([
            'data' => [
                'totals' => [
                    'count' => (int) ($totals->count ?? 0),
                    'amount' => round((float) ($totals->amount ?? 0), 2),
                    'commission' => round((float) ($totals->commission ?? 0), 2),
                    'net' => round((float) ($totals->net ?? 0), 2),
                ],
                'by_status' => $byStatus,
                'by_channel' => $byChannel,
            ],
        ]);
    }

    public function show(Payment $payment): JsonResponse
    {
        $payment->load(['order.campaign', 'order.user', 'attempts']);

        return response()->json(['data' => new PaymentResource($payment)]);
    }

    /** Réconciliation manuelle : interroge la passerelle et applique le statut réel. */
    public function reconcile(Request $request, Payment $payment): JsonResponse
    {
        if (! $payment->provider_payment_id) {
            return response()->json([
                'message' => 'Ce paiement n’a pas de référence fournisseur : réconciliation impossible.',
            ], 409);
        }

        $before = $payment->status->value;
        $payment = $this->payments->reconcile($payment);

        $this->audit->log(
            $payment->status === PaymentStatus::Success ? AuditAction::PaymentConfirmed : AuditAction::PaymentFailed,
            $payment,
            ['status' => $before],
            ['status' => $payment->status->value, 'operation' => 'manual_reconciliation'],
            $request->user()
        );

        return response()->json([
            'message' => 'Paiement réconcilié.',
            'data' => new PaymentResource($payment->load('order.campaign')),
        ]);
    }

    /** Journal des webhooks reçus (traçabilité et diagnostic des intégrations). */
    public function webhooks(Request $request): JsonResponse
    {
        $request->validate([
            'status' => ['nullable', Rule::enum(WebhookStatus::class)],
            'event' => ['nullable', 'string', 'max:48'],
        ]);

        $webhooks = PaymentWebhook::query()
            ->when($request->filled('status'), fn (Builder $q) => $q->where('status', (string) $request->string('status')))
            ->when($request->filled('event'), fn (Builder $q) => $q->where('event', (string) $request->string('event')))
            ->orderByDesc('created_at')
            ->paginate($this->perPage($request));

        return response()->json([
            'data' => collect($webhooks->items())->map(fn (PaymentWebhook $webhook) => [
                'id' => $webhook->id,
                'provider' => $webhook->provider,
                'event' => $webhook->event,
                'provider_reference' => $webhook->provider_reference,
                'status' => $webhook->status->value,
                'error' => $webhook->error,
                'ip' => $webhook->ip,
                'payload' => $webhook->payload,
                'processed_at' => $webhook->processed_at?->toIso8601String(),
                'created_at' => $webhook->created_at?->toIso8601String(),
            ])->all(),
            'meta' => $this->paginationMeta($webhooks),
            'links' => $this->paginationLinks($webhooks),
        ]);
    }

    // ------------------------------------------------------------------ helpers

    /** @return \Illuminate\Database\Query\Builder */
    private function summaryQuery(Request $request): \Illuminate\Database\Query\Builder
    {
        return DB::table('payments')
            ->join('orders', 'orders.id', '=', 'payments.order_id')
            ->leftJoin('campaigns', 'campaigns.id', '=', 'orders.campaign_id')
            ->whereNull('orders.deleted_at')
            ->when($request->filled('status'), fn ($q) => $q->where('payments.status', (string) $request->string('status')))
            ->when($request->filled('channel'), fn ($q) => $q->where('payments.channel', (string) $request->string('channel')))
            ->when($request->filled('currency'), fn ($q) => $q->where('payments.currency', strtoupper((string) $request->string('currency'))))
            ->when($request->filled('from'), fn ($q) => $q->where('payments.created_at', '>=', $request->date('from')))
            ->when($request->filled('to'), fn ($q) => $q->where('payments.created_at', '<=', $request->date('to')->endOfDay()))
            ->when($request->filled('campaign'), function ($q) use ($request) {
                $value = (string) $request->string('campaign');

                // Une campagne est identifiée par son UUID ou son slug public :
                // on ne compare jamais un texte libre à une colonne uuid.
                if (Str::isUuid($value)) {
                    $q->where('campaigns.id', $value);
                } else {
                    $q->where('campaigns.slug', $value);
                }
            });
    }

    /** Restreint une requête Eloquent à une campagne (UUID ou slug). */
    private function scopeCampaign(Builder $query, string $value): void
    {
        $query->whereHas('order.campaign', fn (Builder $q) => $q->where(
            Str::isUuid($value) ? 'id' : 'slug',
            $value
        ));
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
