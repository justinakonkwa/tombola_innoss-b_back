<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\AuditAction;
use App\Enums\PrizeStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\PrizeResource;
use App\Models\Prize;
use App\Services\AuditService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Gestion des lots d'une campagne (cahier des charges §19).
 *
 * Règles structurantes :
 *  - un seul lot principal (`is_main`) par campagne ;
 *  - la quantité déjà attribuée ne peut jamais être réduite (trigger PostgreSQL) ;
 *  - un lot ayant servi au tirage n'est pas supprimable : il est retiré (withdrawn).
 */
class PrizeController extends Controller
{
    private const SORTABLE = ['position', 'created_at', 'name', 'draw_at'];

    /** Champs journalisés en avant/après. */
    private const AUDITED = [
        'name', 'slug', 'quantity', 'quantity_awarded', 'is_main', 'position',
        'status', 'indicative_value', 'currency', 'draw_at',
    ];

    public function __construct(private readonly AuditService $audit)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'search' => ['nullable', 'string', 'max:150'],
            'status' => ['nullable', Rule::enum(PrizeStatus::class)],
            'campaign' => ['nullable', 'string', 'max:180'],
            'sort' => ['nullable', 'string', 'max:32'],
        ]);

        $query = Prize::query()
            ->with('campaign')
            ->when($request->filled('search'), function (Builder $query) use ($request) {
                $term = '%'.mb_strtolower((string) $request->string('search')).'%';
                $query->whereRaw('LOWER(name) LIKE ?', [$term]);
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

        return PrizeResource::collection($query->paginate($this->perPage($request)))->response();
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'campaign_id' => ['required', 'uuid', 'exists:campaigns,id'],
            'name' => ['required', 'string', 'max:150'],
            'slug' => ['nullable', 'string', 'max:160', 'alpha_dash'],
            'description' => ['nullable', 'string', 'max:5000'],
            'media' => ['nullable', 'array'],
            'indicative_value' => ['nullable', 'numeric', 'min:0', 'max:100000000'],
            'currency' => ['nullable', Rule::in(['USD', 'CDF', 'EUR'])],
            'quantity' => ['nullable', 'integer', 'min:1', 'max:1000000'],
            'is_main' => ['nullable', 'boolean'],
            'position' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'draw_at' => ['nullable', 'date'],
            'status' => ['nullable', Rule::enum(PrizeStatus::class)],
        ]);

        $data['slug'] = $this->uniqueSlug($data['campaign_id'], $data['slug'] ?? $data['name']);

        $prize = DB::transaction(function () use ($data, $request) {
            if (! empty($data['is_main'])) {
                // Un seul lot principal par campagne : le nouveau détrône l'ancien.
                Prize::query()->where('campaign_id', $data['campaign_id'])->update(['is_main' => false]);
            }

            $prize = Prize::query()->create($data);
            // Relecture en base : le statut et la quantité attribuée sont posés par
            // des valeurs par défaut PostgreSQL, non hydratées par create().
            $prize->refresh();

            $this->audit->log(AuditAction::PrizeCreated, $prize, [], $this->auditedValues($prize), $request->user());

            return $prize;
        });

        return response()->json([
            'message' => 'Lot créé.',
            'data' => new PrizeResource($prize->load('campaign')),
        ], 201);
    }

    public function show(Prize $prize): JsonResponse
    {
        $prize->load('campaign');

        return response()->json(['data' => new PrizeResource($prize)]);
    }

    public function update(Request $request, Prize $prize): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:150'],
            'slug' => ['sometimes', 'string', 'max:160', 'alpha_dash'],
            'description' => ['nullable', 'string', 'max:5000'],
            'media' => ['nullable', 'array'],
            'indicative_value' => ['nullable', 'numeric', 'min:0', 'max:100000000'],
            'currency' => ['sometimes', Rule::in(['USD', 'CDF', 'EUR'])],
            'quantity' => ['sometimes', 'integer', 'min:1', 'max:1000000'],
            'is_main' => ['sometimes', 'boolean'],
            'position' => ['sometimes', 'integer', 'min:0', 'max:100000'],
            'draw_at' => ['nullable', 'date'],
            'status' => ['sometimes', Rule::enum(PrizeStatus::class)],
        ]);

        // La quantité ne peut pas descendre sous ce qui a déjà été attribué.
        if (array_key_exists('quantity', $data) && (int) $data['quantity'] < (int) $prize->quantity_awarded) {
            throw ValidationException::withMessages([
                'quantity' => 'La quantité ne peut pas être inférieure au nombre de lots déjà attribués.',
            ]);
        }

        if (array_key_exists('slug', $data) && $data['slug'] !== $prize->slug
            && Prize::query()->where('campaign_id', $prize->campaign_id)->where('slug', $data['slug'])->exists()) {
            throw ValidationException::withMessages([
                'slug' => 'Un lot portant ce slug existe déjà pour cette campagne.',
            ]);
        }

        $before = $this->auditedValues($prize);

        DB::transaction(function () use ($prize, $data, $before, $request) {
            if (! empty($data['is_main'])) {
                Prize::query()
                    ->where('campaign_id', $prize->campaign_id)
                    ->whereKeyNot($prize->getKey())
                    ->update(['is_main' => false]);
            }

            $prize->fill($data)->save();

            $this->audit->log(AuditAction::PrizeUpdated, $prize, $before, $this->auditedValues($prize->refresh()), $request->user());
        });

        return response()->json([
            'message' => 'Lot mis à jour.',
            'data' => new PrizeResource($prize->load('campaign')),
        ]);
    }

    public function destroy(Request $request, Prize $prize): JsonResponse
    {
        // Un lot déjà tiré au sort est une pièce d'historique : on le retire, on ne le supprime pas.
        if ((int) $prize->quantity_awarded > 0 || $prize->winners()->exists()) {
            $before = $prize->status->value;
            $prize->forceFill(['status' => PrizeStatus::Withdrawn, 'is_main' => false])->save();

            $this->audit->log(AuditAction::PrizeUpdated, $prize, ['status' => $before], [
                'status' => $prize->status->value,
                'withdrawn' => true,
            ], $request->user());

            return response()->json([
                'message' => 'Lot déjà attribué : il a été retiré au lieu d’être supprimé.',
                'data' => new PrizeResource($prize),
            ]);
        }

        $before = $this->auditedValues($prize);
        $prize->delete();

        $this->audit->log(AuditAction::PrizeUpdated, $prize, $before, ['deleted' => true], $request->user());

        return response()->json(['message' => 'Lot supprimé.']);
    }

    // ------------------------------------------------------------------ helpers

    /** Un slug de lot est unique au sein de sa campagne (contrainte applicative). */
    private function uniqueSlug(string $campaignId, string $source): string
    {
        $base = Str::slug($source) ?: 'lot';
        $slug = $base;
        $suffix = 2;

        while (Prize::query()->where('campaign_id', $campaignId)->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$suffix++;
        }

        return $slug;
    }

    /**
     * @return array<string, mixed>
     */
    private function auditedValues(Prize $prize): array
    {
        $values = [];

        foreach (self::AUDITED as $field) {
            $value = $prize->getAttribute($field);

            if ($value instanceof \BackedEnum) {
                $value = $value->value;
            } elseif ($value instanceof \DateTimeInterface) {
                $value = $value->format(DATE_ATOM);
            }

            $values[$field] = $value;
        }

        return $values;
    }

    private function perPage(Request $request): int
    {
        return max(1, min((int) $request->integer('per_page', 25), 100));
    }

    /**
     * @param  array<int, string>  $allowed
     */
    private function applySort(Builder $query, Request $request, array $allowed = self::SORTABLE, string $default = 'position'): void
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
