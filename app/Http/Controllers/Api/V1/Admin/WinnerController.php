<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\AuditAction;
use App\Enums\WinnerStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\WinnerResource;
use App\Models\Winner;
use App\Services\AuditService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Suivi des gagnants (cahier des charges §23).
 *
 * Le parcours d'un gagnant est strictement ordonné : contacté → identité
 * vérifiée → validé → lot remis. La remise exige une preuve (PV, photos) :
 * sans preuve, aucun lot ne peut être déclaré remis.
 */
class WinnerController extends Controller
{
    /** Transitions légales entre statuts de gagnant. */
    private const TRANSITIONS = [
        'pending' => ['contacted', 'verified', 'rejected', 'forfeited'],
        'contacted' => ['verified', 'validated', 'rejected', 'forfeited'],
        'verified' => ['validated', 'rejected', 'forfeited'],
        'validated' => ['delivered', 'forfeited'],
        'delivered' => [],
        'rejected' => [],
        'forfeited' => [],
    ];

    private const SORTABLE = ['rank', 'created_at', 'delivered_at'];

    public function __construct(private readonly AuditService $audit)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'search' => ['nullable', 'string', 'max:180'],
            'status' => ['nullable', Rule::enum(WinnerStatus::class)],
            'draw_id' => ['nullable', 'uuid'],
            'prize_id' => ['nullable', 'uuid'],
            'campaign' => ['nullable', 'string', 'max:180'],
            'is_published' => ['nullable', 'boolean'],
            'sort' => ['nullable', 'string', 'max:32'],
        ]);

        $query = Winner::query()
            ->with(['user', 'prize', 'ticket', 'draw.campaign'])
            ->when($request->filled('search'), function (Builder $query) use ($request) {
                $term = '%'.mb_strtolower((string) $request->string('search')).'%';

                $query->where(fn (Builder $q) => $q
                    ->whereHas('user', fn (Builder $u) => $u
                        ->whereRaw('LOWER(first_name) LIKE ?', [$term])
                        ->orWhereRaw('LOWER(last_name) LIKE ?', [$term]))
                    ->orWhereHas('ticket', fn (Builder $t) => $t->whereRaw('LOWER(ticket_number) LIKE ?', [$term])));
            })
            ->when($request->filled('status'), fn (Builder $q) => $q->where('status', (string) $request->string('status')))
            ->when($request->filled('draw_id'), fn (Builder $q) => $q->where('draw_id', (string) $request->string('draw_id')))
            ->when($request->filled('prize_id'), fn (Builder $q) => $q->where('prize_id', (string) $request->string('prize_id')))
            ->when($request->has('is_published'), fn (Builder $q) => $q->where('is_published', $request->boolean('is_published')))
            ->when($request->filled('campaign'), function (Builder $query) use ($request) {
                $value = (string) $request->string('campaign');

                $query->whereHas('draw.campaign', fn (Builder $q) => $q->where(
                    Str::isUuid($value) ? 'id' : 'slug',
                    $value
                ));
            });

        $this->applySort($query, $request);

        return WinnerResource::collection($query->paginate($this->perPage($request)))->response();
    }

    /** Détail d'un gagnant : dossier complet pour le back-office. */
    public function show(Winner $winner): JsonResponse
    {
        return response()->json([
            'data' => new WinnerResource(
                $winner->load(['user', 'prize', 'ticket', 'draw.campaign'])
            ),
        ]);
    }

    /** Met à jour les informations de suivi (notes internes, preuve, publication). */
    public function update(Request $request, Winner $winner): JsonResponse
    {
        $data = $request->validate([
            'notes' => ['nullable', 'string', 'max:2000'],
            'proof' => ['nullable', 'array'],
            'is_published' => ['sometimes', 'boolean'],
        ]);

        $before = [
            'notes' => $winner->notes,
            'is_published' => (bool) $winner->is_published,
            'proof' => $winner->proof,
        ];

        $winner->fill($data)->save();

        $this->audit->log(AuditAction::WinnerValidated, $winner, $before, [
            'notes' => $winner->notes,
            'is_published' => (bool) $winner->is_published,
            'proof' => $winner->proof,
        ], $request->user());

        return response()->json([
            'message' => 'Gagnant mis à jour.',
            'data' => new WinnerResource($winner->refresh()->load(['user', 'prize', 'ticket', 'draw.campaign'])),
        ]);
    }

    /** Fait progresser le dossier d'un gagnant en respectant les transitions légales. */
    public function changeStatus(Request $request, Winner $winner): JsonResponse
    {
        $data = $request->validate([
            'status' => ['required', Rule::enum(WinnerStatus::class)],
            'proof' => ['nullable', 'array'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $target = WinnerStatus::from($data['status']);
        $current = $winner->status;

        if ($target === $current) {
            return response()->json([
                'message' => 'Le gagnant est déjà à ce statut.',
                'status' => $current->value,
            ], 409);
        }

        if (! in_array($target->value, self::TRANSITIONS[$current->value] ?? [], true)) {
            throw ValidationException::withMessages([
                'status' => sprintf(
                    'Transition illégale de « %s » vers « %s ».',
                    $current->label(),
                    $target->label()
                ),
            ]);
        }

        // La remise du lot est un acte matériel : elle exige une preuve conservée en base.
        if ($target === WinnerStatus::Delivered && empty($data['proof'])) {
            throw ValidationException::withMessages([
                'proof' => 'La remise du lot exige une preuve (procès-verbal, photos, signature).',
            ]);
        }

        $before = [
            'status' => $current->value,
            'notified_at' => $winner->notified_at?->toIso8601String(),
            'identity_verified_at' => $winner->identity_verified_at?->toIso8601String(),
            'delivered_at' => $winner->delivered_at?->toIso8601String(),
        ];

        $winner->status = $target;
        $winner->handled_by = $request->user()->id;

        // Horodatages probants : ils ne sont jamais écrasés s'ils existent déjà.
        match ($target) {
            WinnerStatus::Contacted => $winner->notified_at ??= now(),
            WinnerStatus::Verified => $winner->identity_verified_at ??= now(),
            WinnerStatus::Delivered => $winner->delivered_at = now(),
            default => null,
        };

        if (array_key_exists('notes', $data)) {
            $winner->notes = $data['notes'];
        }

        if (! empty($data['proof'])) {
            $winner->proof = $data['proof'];
        }

        $winner->save();

        $this->audit->log(
            $target === WinnerStatus::Delivered ? AuditAction::WinnerDelivered : AuditAction::WinnerValidated,
            $winner,
            $before,
            [
                'status' => $target->value,
                'notified_at' => $winner->notified_at?->toIso8601String(),
                'identity_verified_at' => $winner->identity_verified_at?->toIso8601String(),
                'delivered_at' => $winner->delivered_at?->toIso8601String(),
            ],
            $request->user()
        );

        return response()->json([
            'message' => 'Statut du gagnant mis à jour.',
            'data' => new WinnerResource($winner->refresh()->load(['user', 'prize', 'ticket', 'draw.campaign'])),
        ]);
    }

    private function perPage(Request $request): int
    {
        return max(1, min((int) $request->integer('per_page', 25), 100));
    }

    /**
     * @param  array<int, string>  $allowed
     */
    private function applySort(Builder $query, Request $request, array $allowed = self::SORTABLE, string $default = 'rank'): void
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
