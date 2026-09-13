<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\RiskAction;
use App\Enums\RiskLevel;
use App\Http\Controllers\Controller;
use App\Models\RiskAssessment;
use App\Services\RiskService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Validation\Rule;

/**
 * Alertes anti-fraude (cahier des charges §27).
 *
 * L'évaluation est automatique ; la revue manuelle permet de débloquer un
 * participant légitime sans jamais éditer le score calculé par le moteur.
 * La décision est journalisée dans `audit_logs` par le RiskService.
 */
class RiskController extends Controller
{
    private const SORTABLE = ['created_at', 'score', 'reviewed_at'];

    public function __construct(private readonly RiskService $risk)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'level' => ['nullable', Rule::enum(RiskLevel::class)],
            'action' => ['nullable', Rule::enum(RiskAction::class)],
            'is_reviewed' => ['nullable', 'boolean'],
            'user_id' => ['nullable', 'uuid'],
            'order_id' => ['nullable', 'uuid'],
            'min_score' => ['nullable', 'integer', 'min:0', 'max:100'],
            'sort' => ['nullable', 'string', 'max:32'],
        ]);

        $query = RiskAssessment::query()
            ->with(['user', 'order', 'reviewer'])
            ->when($request->filled('level'), fn (Builder $q) => $q->where('level', (string) $request->string('level')))
            ->when($request->filled('action'), fn (Builder $q) => $q->where('action', (string) $request->string('action')))
            ->when($request->has('is_reviewed'), fn (Builder $q) => $q->where('is_reviewed', $request->boolean('is_reviewed')))
            ->when($request->filled('user_id'), fn (Builder $q) => $q->where('user_id', (string) $request->string('user_id')))
            ->when($request->filled('order_id'), fn (Builder $q) => $q->where('order_id', (string) $request->string('order_id')))
            ->when($request->filled('min_score'), fn (Builder $q) => $q->where('score', '>=', (int) $request->integer('min_score')));

        $this->applySort($query, $request);

        $assessments = $query->paginate($this->perPage($request));

        return response()->json([
            'data' => collect($assessments->items())->map(fn (RiskAssessment $assessment) => $this->presentAssessment($assessment))->all(),
            'meta' => $this->paginationMeta($assessments),
            'links' => $this->paginationLinks($assessments),
        ]);
    }

    /** Revue manuelle d'une alerte : autorise ou bloque explicitement. */
    public function review(Request $request, RiskAssessment $assessment): JsonResponse
    {
        $data = $request->validate([
            'allow' => ['required', 'boolean'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        if ($assessment->is_reviewed) {
            return response()->json([
                'message' => 'Cette évaluation a déjà été revue.',
                'reviewed_at' => $assessment->reviewed_at?->toIso8601String(),
            ], 409);
        }

        $assessment = $this->risk->review(
            $assessment,
            $request->user(),
            $request->boolean('allow'),
            $data['note'] ?? null,
        );

        return response()->json([
            'message' => $request->boolean('allow')
                ? 'Alerte revue : participant autorisé.'
                : 'Alerte revue : participant bloqué.',
            'data' => $this->presentAssessment($assessment->load(['user', 'order', 'reviewer'])),
        ]);
    }

    // ------------------------------------------------------------------ helpers

    /**
     * @return array<string, mixed>
     */
    private function presentAssessment(RiskAssessment $assessment): array
    {
        return [
            'id' => $assessment->id,
            'user_id' => $assessment->user_id,
            'user_name' => $assessment->user?->fullName(),
            'order_id' => $assessment->order_id,
            'order_reference' => $assessment->order?->reference,
            'score' => (int) $assessment->score,
            'level' => $assessment->level->value,
            'level_label' => $assessment->level->label(),
            'action' => $assessment->action->value,
            'signals' => $assessment->signals ?? [],
            'is_reviewed' => (bool) $assessment->is_reviewed,
            'reviewed_by' => $assessment->reviewer?->fullName(),
            'reviewed_at' => $assessment->reviewed_at?->toIso8601String(),
            'review_note' => $assessment->review_note,
            'created_at' => $assessment->created_at?->toIso8601String(),
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
