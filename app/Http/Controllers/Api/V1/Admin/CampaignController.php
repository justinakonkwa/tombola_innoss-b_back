<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\AuditAction;
use App\Enums\CampaignStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\CampaignResource;
use App\Models\Campaign;
use App\Services\AuditService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Back-office des campagnes et de leurs paramètres (cahier des charges §18).
 *
 * Le serveur reste seul maître des compteurs de vente : aucune valeur de
 * `tickets_sold` / `tickets_reserved` n'est acceptée depuis le client.
 */
class CampaignController extends Controller
{
    private const SORTABLE = ['created_at', 'name', 'starts_at', 'ends_at', 'draw_at', 'tickets_sold'];

    /** Champs métier journalisés en avant/après dans le journal d'audit. */
    private const AUDITED = [
        'name', 'slug', 'ticket_price', 'currency', 'max_tickets', 'starts_at', 'ends_at',
        'draw_at', 'status', 'is_featured', 'min_tickets_per_order', 'max_tickets_per_order',
        'max_tickets_per_user',
    ];

    public function __construct(private readonly AuditService $audit)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'search' => ['nullable', 'string', 'max:180'],
            'status' => ['nullable', Rule::enum(CampaignStatus::class)],
            'is_featured' => ['nullable', 'boolean'],
            'sort' => ['nullable', 'string', 'max:32'],
        ]);

        $query = Campaign::query()
            ->with(['mainPrize'])
            ->when($request->filled('search'), function (Builder $query) use ($request) {
                $term = '%'.mb_strtolower((string) $request->string('search')).'%';

                $query->where(fn (Builder $q) => $q
                    ->whereRaw('LOWER(name) LIKE ?', [$term])
                    ->orWhereRaw('LOWER(slug) LIKE ?', [$term]));
            })
            ->when($request->filled('status'), fn (Builder $q) => $q->where('status', (string) $request->string('status')))
            ->when($request->has('is_featured'), fn (Builder $q) => $q->where('is_featured', $request->boolean('is_featured')));

        $this->applySort($query, $request);

        return CampaignResource::collection($query->paginate($this->perPage($request)))->response();
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'slug' => ['nullable', 'string', 'max:160', 'alpha_dash', 'unique:campaigns,slug'],
            'description' => ['nullable', 'string', 'max:5000'],
            'short_description' => ['nullable', 'string', 'max:500'],
            'hero_media_url' => ['nullable', 'url', 'max:2048'],
            'hero_poster_url' => ['nullable', 'url', 'max:2048'],
            'og_image_url' => ['nullable', 'url', 'max:2048'],
            'ticket_price' => ['required', 'numeric', 'min:0.01', 'max:1000000'],
            'currency' => ['nullable', Rule::in(['USD', 'CDF', 'EUR'])],
            'max_tickets' => ['required', 'integer', 'min:1', 'max:10000000'],
            'min_tickets_per_order' => ['nullable', 'integer', 'min:1', 'max:10000'],
            'max_tickets_per_order' => ['nullable', 'integer', 'min:1', 'max:10000'],
            'max_tickets_per_user' => ['nullable', 'integer', 'min:1', 'max:10000000'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date'],
            'draw_at' => ['nullable', 'date'],
            'status' => ['nullable', Rule::enum(CampaignStatus::class)],
            'is_featured' => ['nullable', 'boolean'],
            'terms_url' => ['nullable', 'url', 'max:2048'],
            'settings' => ['nullable', 'array'],
        ]);

        $this->assertChronology($data);

        if (! empty($data['min_tickets_per_order']) && ! empty($data['max_tickets_per_order'])
            && (int) $data['max_tickets_per_order'] < (int) $data['min_tickets_per_order']) {
            throw ValidationException::withMessages([
                'max_tickets_per_order' => 'Le maximum par commande doit être supérieur ou égal au minimum.',
            ]);
        }

        $data['slug'] = $this->uniqueSlug($data['slug'] ?? $data['name']);
        $data['status'] = $data['status'] ?? CampaignStatus::Draft->value;
        $data['created_by'] = $request->user()->id;

        $campaign = Campaign::query()->create($data);
        // Relecture en base : les valeurs par défaut (statut, devise, compteurs)
        // ne sont pas hydratées par un create(), or les resources les exposent.
        $campaign->refresh();

        $this->audit->log(AuditAction::CampaignCreated, $campaign, [], $this->auditedValues($campaign), $request->user());

        return response()->json([
            'message' => 'Campagne créée.',
            'data' => new CampaignResource($campaign->load('mainPrize')),
        ], 201);
    }

    public function show(Campaign $campaign): JsonResponse
    {
        $campaign->load(['prizes', 'mainPrize']);

        return response()->json(['data' => new CampaignResource($campaign)]);
    }

    public function update(Request $request, Campaign $campaign): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:150'],
            'slug' => ['sometimes', 'string', 'max:160', 'alpha_dash', Rule::unique('campaigns', 'slug')->ignore($campaign->id)],
            'description' => ['nullable', 'string', 'max:5000'],
            'short_description' => ['nullable', 'string', 'max:500'],
            'hero_media_url' => ['nullable', 'url', 'max:2048'],
            'hero_poster_url' => ['nullable', 'url', 'max:2048'],
            'og_image_url' => ['nullable', 'url', 'max:2048'],
            'ticket_price' => ['sometimes', 'numeric', 'min:0.01', 'max:1000000'],
            'currency' => ['sometimes', Rule::in(['USD', 'CDF', 'EUR'])],
            'max_tickets' => ['sometimes', 'integer', 'min:1', 'max:10000000'],
            'min_tickets_per_order' => ['sometimes', 'integer', 'min:1', 'max:10000'],
            'max_tickets_per_order' => ['sometimes', 'integer', 'min:1', 'max:10000'],
            'max_tickets_per_user' => ['nullable', 'integer', 'min:1', 'max:10000000'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date'],
            'draw_at' => ['nullable', 'date'],
            'is_featured' => ['sometimes', 'boolean'],
            'terms_url' => ['nullable', 'url', 'max:2048'],
            'settings' => ['nullable', 'array'],
        ]);

        // On ne laisse pas une campagne rétrécir sous le nombre de tickets déjà vendus.
        if (array_key_exists('max_tickets', $data) && (int) $data['max_tickets'] < (int) $campaign->tickets_sold) {
            throw ValidationException::withMessages([
                'max_tickets' => 'Le nombre maximum de tickets ne peut pas être inférieur aux tickets déjà vendus.',
            ]);
        }

        $before = $this->auditedValues($campaign);

        $campaign->fill($data)->save();

        $this->audit->log(AuditAction::CampaignUpdated, $campaign, $before, $this->auditedValues($campaign->refresh()), $request->user());

        return response()->json([
            'message' => 'Campagne mise à jour.',
            'data' => new CampaignResource($campaign->load('mainPrize')),
        ]);
    }

    public function destroy(Request $request, Campaign $campaign): JsonResponse
    {
        // Suppression douce : l'historique des commandes et tickets reste intact.
        $campaign->delete();

        $this->audit->log(AuditAction::CampaignUpdated, $campaign, $this->auditedValues($campaign), ['deleted' => true], $request->user());

        return response()->json(['message' => 'Campagne archivée.']);
    }

    public function changeStatus(Request $request, Campaign $campaign): JsonResponse
    {
        $data = $request->validate([
            'status' => ['required', Rule::enum(CampaignStatus::class)],
        ]);

        $before = $campaign->status->value;
        $campaign->forceFill(['status' => CampaignStatus::from($data['status'])])->save();

        $this->audit->log(AuditAction::CampaignStatusChanged, $campaign, ['status' => $before], [
            'status' => $campaign->status->value,
        ], $request->user());

        return response()->json([
            'message' => 'Statut de la campagne mis à jour.',
            'data' => new CampaignResource($campaign->load('mainPrize')),
        ]);
    }

    // ------------------------------------------------------------------ helpers

    /**
     * Contrôle de cohérence des dates : les ventes ne peuvent pas s'achever avant
     * de commencer, ni le tirage avoir lieu avant la fermeture.
     *
     * @param  array<string, mixed>  $data
     */
    private function assertChronology(array $data): void
    {
        $starts = ! empty($data['starts_at']) ? strtotime((string) $data['starts_at']) : null;
        $ends = ! empty($data['ends_at']) ? strtotime((string) $data['ends_at']) : null;
        $draw = ! empty($data['draw_at']) ? strtotime((string) $data['draw_at']) : null;

        if ($starts !== null && $ends !== null && $ends <= $starts) {
            throw ValidationException::withMessages([
                'ends_at' => 'La date de fin doit être postérieure à la date de début.',
            ]);
        }

        if ($ends !== null && $draw !== null && $draw < $ends) {
            throw ValidationException::withMessages([
                'draw_at' => 'La date du tirage doit être postérieure à la fermeture des ventes.',
            ]);
        }
    }

    /** Slug unique : on suffixe plutôt que de refuser une campagne homonyme. */
    private function uniqueSlug(string $source): string
    {
        $base = Str::slug($source) ?: 'campagne';
        $slug = $base;
        $suffix = 2;

        while (Campaign::withTrashed()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$suffix++;
        }

        return $slug;
    }

    /**
     * @return array<string, mixed>
     */
    private function auditedValues(Campaign $campaign): array
    {
        $values = [];

        foreach (self::AUDITED as $field) {
            $value = $campaign->getAttribute($field);

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
