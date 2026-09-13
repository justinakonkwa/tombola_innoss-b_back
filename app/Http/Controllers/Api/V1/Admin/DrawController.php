<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\DrawStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\DrawResource;
use App\Models\Campaign;
use App\Models\Draw;
use App\Services\DrawService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Pilotage du tirage commit-reveal (cahier des charges §20–§22).
 *
 * Chaque étape (création, fermeture, snapshot, exécution, publication) est
 * journalisée dans `audit_logs` par le DrawService lui-même, avec l'acteur
 * authentifié : le back-office ne peut pas exécuter un tirage anonymement.
 */
class DrawController extends Controller
{
    private const SORTABLE = ['created_at', 'executed_at', 'published_at', 'reference'];

    public function __construct(private readonly DrawService $draws)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'search' => ['nullable', 'string', 'max:64'],
            'status' => ['nullable', Rule::enum(DrawStatus::class)],
            'campaign' => ['nullable', 'string', 'max:180'],
            'sort' => ['nullable', 'string', 'max:32'],
        ]);

        $query = Draw::query()
            ->with(['campaign', 'executor'])
            ->when($request->filled('search'), function (Builder $query) use ($request) {
                $term = '%'.mb_strtolower((string) $request->string('search')).'%';
                $query->whereRaw('LOWER(reference) LIKE ?', [$term]);
            })
            ->when($request->filled('status'), fn (Builder $q) => $q->where('status', (string) $request->string('status')))
            ->when($request->filled('campaign'), function (Builder $query) use ($request) {
                $value = (string) $request->string('campaign');

                $query->whereHas('campaign', fn (Builder $q) => $q->where(
                    Str::isUuid($value) ? 'id' : 'slug',
                    $value
                ));
            });

        $this->applySort($query, $request);

        return DrawResource::collection($query->paginate($this->perPage($request)))->response();
    }

    /** Ouvre un tirage : le serveur publie immédiatement son engagement SHA-256. */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'campaign_id' => ['required', 'uuid', 'exists:campaigns,id'],
            'client_seed' => ['nullable', 'string', 'max:128'],
        ]);

        // La graine publique n'est consommée qu'à l'exécution : elle est acceptée
        // ici pour la compatibilité de l'API mais l'engagement seul est publié.
        $campaign = Campaign::query()->findOrFail($data['campaign_id']);

        $draw = $this->draws->create($campaign, $request->user());

        return response()->json([
            'message' => 'Tirage créé : l’engagement du serveur est publié.',
            'data' => new DrawResource($draw->load('campaign')),
        ], 201);
    }

    public function show(Draw $draw): JsonResponse
    {
        $draw->load(['campaign', 'executor', 'winners.prize', 'winners.ticket', 'winners.user']);

        return response()->json(['data' => new DrawResource($draw)]);
    }

    /** Ferme les ventes de la campagne liée : plus aucun ticket ne peut être vendu. */
    public function close(Request $request, Draw $draw): JsonResponse
    {
        $draw = $this->draws->closeSales($draw, $request->user());

        return response()->json([
            'message' => 'Ventes fermées.',
            'data' => new DrawResource($draw->load('campaign')),
        ]);
    }

    /** Fige le pool des tickets éligibles et son empreinte (opération définitive). */
    public function snapshot(Request $request, Draw $draw): JsonResponse
    {
        $draw = $this->draws->snapshot($draw, $request->user());

        return response()->json([
            'message' => 'Pool figé : l’empreinte des tickets est enregistrée.',
            'data' => new DrawResource($draw->load('campaign')),
        ]);
    }

    /** Exécute le tirage avec l'aléa public fourni par l'administrateur. */
    public function execute(Request $request, Draw $draw): JsonResponse
    {
        $data = $request->validate([
            'client_seed' => [
                'required',
                'string',
                'min:'.max(1, (int) config('tombola.draws.min_client_seed_length', 8)),
                'max:128',
            ],
        ]);

        $draw = $this->draws->execute($draw, $data['client_seed'], $request->user());

        return response()->json([
            'message' => 'Tirage exécuté : la graine du serveur est révélée.',
            'data' => new DrawResource($draw->load('campaign', 'winners.prize', 'winners.ticket', 'winners.user')),
        ]);
    }

    /** Publie le résultat et notifie les gagnants. */
    public function publish(Request $request, Draw $draw): JsonResponse
    {
        $draw = $this->draws->publish($draw, $request->user());

        return response()->json([
            'message' => 'Tirage publié et gagnants notifiés.',
            'data' => new DrawResource($draw->load('campaign', 'winners.prize', 'winners.ticket', 'winners.user')),
        ]);
    }

    /** Vérification indépendante : recalcule engagement, pool et ordre des gagnants. */
    public function verify(Draw $draw): JsonResponse
    {
        $verification = $this->draws->verify($draw);

        return response()->json([
            'data' => [
                'draw' => new DrawResource($draw->load('campaign', 'winners.prize', 'winners.ticket', 'winners.user')),
                'verification' => $verification,
                'verified' => $verification['commitment_valid']
                    && $verification['pool_hash_valid']
                    && $verification['winners_valid'],
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
