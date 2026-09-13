<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\AuditAction;
use App\Http\Controllers\Controller;
use App\Models\SecurityEvent;
use App\Services\AuditService;
use App\Services\SecurityEventService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Validation\Rule;

/**
 * Supervision de la sécurité (cahier des charges §13, §36).
 *
 * Les événements sont générés par la plateforme ; le back-office ne peut que
 * les consulter et les marquer comme traités (jamais les modifier/supprimer).
 */
class SecurityController extends Controller
{
    private const SEVERITIES = ['info', 'warning', 'high', 'critical'];

    private const SORTABLE = ['created_at', 'severity', 'resolved_at'];

    public function __construct(
        private readonly SecurityEventService $security,
        private readonly AuditService $audit,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'type' => ['nullable', 'string', 'max:48'],
            'severity' => ['nullable', Rule::in(self::SEVERITIES)],
            'is_resolved' => ['nullable', 'boolean'],
            'user_id' => ['nullable', 'uuid'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'sort' => ['nullable', 'string', 'max:32'],
        ]);

        $query = SecurityEvent::query()
            ->with('user')
            ->when($request->filled('search'), function (Builder $query) use ($request) {
                $term = '%'.mb_strtolower((string) $request->string('search')).'%';

                $query->where(fn (Builder $q) => $q
                    ->whereRaw("LOWER(COALESCE(description, '')) LIKE ?", [$term])
                    ->orWhereRaw("LOWER(COALESCE(ip, '')) LIKE ?", [$term])
                    ->orWhereRaw('LOWER(type) LIKE ?', [$term]));
            })
            ->when($request->filled('type'), fn (Builder $q) => $q->where('type', (string) $request->string('type')))
            ->when($request->filled('severity'), fn (Builder $q) => $q->where('severity', (string) $request->string('severity')))
            ->when($request->has('is_resolved'), fn (Builder $q) => $q->where('is_resolved', $request->boolean('is_resolved')))
            ->when($request->filled('user_id'), fn (Builder $q) => $q->where('user_id', (string) $request->string('user_id')))
            ->when($request->filled('from'), fn (Builder $q) => $q->where('created_at', '>=', $request->date('from')))
            ->when($request->filled('to'), fn (Builder $q) => $q->where('created_at', '<=', $request->date('to')->endOfDay()));

        $this->applySort($query, $request);

        $events = $query->paginate($this->perPage($request));

        return response()->json([
            'data' => collect($events->items())->map(fn (SecurityEvent $event) => $this->presentEvent($event))->all(),
            'meta' => $this->paginationMeta($events),
            'links' => $this->paginationLinks($events),
        ]);
    }

    /** Marque un événement de sécurité comme traité. */
    public function resolve(Request $request, SecurityEvent $event): JsonResponse
    {
        if ($event->is_resolved) {
            return response()->json([
                'message' => 'Cet événement est déjà résolu.',
                'resolved_at' => $event->resolved_at?->toIso8601String(),
            ], 409);
        }

        $event->forceFill(['is_resolved' => true, 'resolved_at' => now()])->save();

        // Double traçabilité : événement de sécurité (supervision) + journal d'audit.
        $this->security->log(
            'security_event_resolved',
            'info',
            $request->user(),
            ['security_event_id' => $event->id, 'type' => $event->type],
            'Événement de sécurité marqué comme résolu.'
        );

        $this->audit->log(AuditAction::RiskReviewed, $event, ['is_resolved' => false], [
            'is_resolved' => true,
            'resolved_at' => $event->resolved_at?->toIso8601String(),
        ], $request->user());

        return response()->json([
            'message' => 'Événement de sécurité résolu.',
            'data' => $this->presentEvent($event->load('user')),
        ]);
    }

    // ------------------------------------------------------------------ helpers

    /**
     * @return array<string, mixed>
     */
    private function presentEvent(SecurityEvent $event): array
    {
        return [
            'id' => $event->id,
            'type' => $event->type,
            'severity' => $event->severity,
            'user_id' => $event->user_id,
            'user_name' => $event->user?->fullName(),
            'ip' => $event->ip,
            'user_agent' => $event->user_agent,
            'description' => $event->description,
            'metadata' => $event->metadata ?? [],
            'is_resolved' => (bool) $event->is_resolved,
            'resolved_at' => $event->resolved_at?->toIso8601String(),
            'created_at' => $event->created_at?->toIso8601String(),
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
