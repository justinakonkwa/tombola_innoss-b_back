<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\AuditLogResource;
use App\Models\AuditLog;
use App\Services\AuditService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Journal d'audit chaîné (cahier des charges §17).
 *
 * Consultation seule : la table est append-only (trigger PostgreSQL), aucune
 * action d'écriture n'est exposée ici. La vérification recalcule toute la
 * chaîne de hachage pour détecter une altération ou une suppression.
 */
class AuditController extends Controller
{
    /** Colonnes de tri autorisées : un nom de colonne client n'est jamais injecté. */
    private const SORTABLE = ['created_at', 'action', 'actor_email'];

    public function __construct(private readonly AuditService $audit)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'action' => ['nullable', 'string', 'max:64'],
            'resource_type' => ['nullable', 'string', 'max:64'],
            'actor_id' => ['nullable', 'uuid'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'sort' => ['nullable', 'string', 'max:32'],
        ]);

        $query = AuditLog::query()
            ->when($request->filled('search'), function (Builder $query) use ($request) {
                // Recherche insensible à la casse et portable (pas de ILIKE propriétaire).
                $term = '%'.mb_strtolower((string) $request->string('search')).'%';

                $query->where(fn (Builder $q) => $q
                    ->whereRaw('LOWER(action) LIKE ?', [$term])
                    ->orWhereRaw("LOWER(COALESCE(actor_email, '')) LIKE ?", [$term])
                    ->orWhereRaw("LOWER(COALESCE(resource_id, '')) LIKE ?", [$term]));
            })
            ->when($request->filled('action'), fn (Builder $q) => $q->where('action', (string) $request->string('action')))
            ->when($request->filled('resource_type'), fn (Builder $q) => $q->where('resource_type', (string) $request->string('resource_type')))
            ->when($request->filled('actor_id'), fn (Builder $q) => $q->where('actor_id', (string) $request->string('actor_id')))
            ->when($request->filled('from'), fn (Builder $q) => $q->where('created_at', '>=', $request->date('from')))
            ->when($request->filled('to'), fn (Builder $q) => $q->where('created_at', '<=', $request->date('to')->endOfDay()));

        $this->applySort($query, $request);

        return AuditLogResource::collection($query->paginate($this->perPage($request)))->response();
    }

    /** Vérifie l'intégrité de la chaîne d'audit (détection d'altération/suppression). */
    public function verify(): JsonResponse
    {
        return response()->json(['data' => $this->audit->verifyChain()]);
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
