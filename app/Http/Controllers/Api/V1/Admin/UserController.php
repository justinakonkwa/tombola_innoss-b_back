<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\AuditAction;
use App\Enums\RoleName;
use App\Enums\UserStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\Role;
use App\Models\User;
use App\Services\AuditService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Gestion des participants et de leurs habilitations (cahier des charges §12, §16).
 *
 * Un administrateur ne peut pas se retirer lui-même le rôle super administrateur :
 * cela garantirait de perdre le dernier compte capable de tout administrer.
 */
class UserController extends Controller
{
    private const SORTABLE = ['created_at', 'last_login_at', 'risk_score', 'first_name'];

    public function __construct(private readonly AuditService $audit)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'search' => ['nullable', 'string', 'max:180'],
            'status' => ['nullable', Rule::enum(UserStatus::class)],
            'role' => ['nullable', Rule::enum(RoleName::class)],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'sort' => ['nullable', 'string', 'max:32'],
        ]);

        $query = User::query()
            ->with('roles')
            ->withCount('tickets')
            ->when($request->filled('search'), function (Builder $query) use ($request) {
                $term = '%'.mb_strtolower((string) $request->string('search')).'%';

                $query->where(fn (Builder $q) => $q
                    ->whereRaw('LOWER(first_name) LIKE ?', [$term])
                    ->orWhereRaw('LOWER(last_name) LIKE ?', [$term])
                    ->orWhereRaw('LOWER(email) LIKE ?', [$term])
                    ->orWhereRaw('LOWER(phone) LIKE ?', [$term]));
            })
            ->when($request->filled('status'), fn (Builder $q) => $q->where('status', (string) $request->string('status')))
            ->when($request->filled('role'), fn (Builder $q) => $q->whereHas(
                'roles',
                fn (Builder $r) => $r->where('name', (string) $request->string('role'))
            ))
            ->when($request->filled('from'), fn (Builder $q) => $q->where('created_at', '>=', $request->date('from')))
            ->when($request->filled('to'), fn (Builder $q) => $q->where('created_at', '<=', $request->date('to')->endOfDay()));

        $this->applySort($query, $request);

        return UserResource::collection($query->paginate($this->perPage($request)))->response();
    }

    public function show(User $user): JsonResponse
    {
        $user->load('roles')->loadCount('tickets');

        return response()->json(['data' => new UserResource($user)]);
    }

    /** Suspend, bloque ou réactive un compte participant. */
    public function changeStatus(Request $request, User $user): JsonResponse
    {
        $data = $request->validate([
            'status' => ['required', Rule::enum(UserStatus::class)],
        ]);

        $status = UserStatus::from($data['status']);
        $before = ['status' => $user->status?->value, 'locked_until' => $user->locked_until?->toIso8601String()];

        $attributes = ['status' => $status];

        // Une réactivation lève le verrouillage anti-brute-force et remet le
        // compteur à zéro ; une suspension ne touche pas aux colonnes par défaut.
        if ($status === UserStatus::Active) {
            $attributes['locked_until'] = null;
            $attributes['failed_login_attempts'] = 0;
        }

        $user->forceFill($attributes)->save();

        // Aucun cas dédié à la réactivation dans l'enum : on journalise la
        // transition complète sous UserSuspended (avant/après explicites).
        $this->audit->log(AuditAction::UserSuspended, $user, $before, [
            'status' => $status->value,
        ], $request->user());

        return response()->json([
            'message' => 'Statut du compte mis à jour.',
            'data' => new UserResource($user->refresh()->load('roles')->loadCount('tickets')),
        ]);
    }

    /** Remplace l'ensemble des rôles d'un utilisateur (RBAC). */
    public function assignRoles(Request $request, User $user): JsonResponse
    {
        $data = $request->validate([
            'roles' => ['required', 'array', 'min:1'],
            'roles.*' => ['string', 'distinct', Rule::enum(RoleName::class), 'exists:roles,name'],
        ]);

        $roles = array_values(array_unique($data['roles']));
        $actor = $request->user();

        // Garde-fou : un super administrateur ne peut pas se démettre lui-même,
        // sous peine de perdre le dernier accès complet au back-office.
        if ($user->id === $actor->id
            && $user->isSuperAdmin()
            && ! in_array(RoleName::SuperAdmin->value, $roles, true)) {
            throw ValidationException::withMessages([
                'roles' => 'Vous ne pouvez pas retirer votre propre rôle super administrateur.',
            ]);
        }

        $before = $user->roles()->pluck('name')->all();
        $roleIds = Role::query()->whereIn('name', $roles)->pluck('id')->all();

        $user->roles()->syncWithPivotValues($roleIds, ['assigned_by' => $actor->id]);
        $user->load('roles');
        $after = $user->roles->pluck('name')->all();

        $this->audit->log(AuditAction::RoleAssigned, $user, ['roles' => $before], [
            'roles' => $after,
        ], $actor);

        return response()->json([
            'message' => 'Rôles mis à jour.',
            'data' => new UserResource($user->loadCount('tickets')),
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
