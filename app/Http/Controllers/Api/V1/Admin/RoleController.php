<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\AuditAction;
use App\Enums\PermissionName;
use App\Http\Controllers\Controller;
use App\Models\Permission;
use App\Models\Role;
use App\Services\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Rôles et permissions du back-office (cahier des charges §16).
 *
 * Les permissions elles-mêmes sont seedées : le back-office ne peut que les
 * rattacher ou les détacher d'un rôle, jamais en inventer de nouvelles.
 */
class RoleController extends Controller
{
    public function __construct(private readonly AuditService $audit)
    {
    }

    public function index(): JsonResponse
    {
        $roles = Role::query()
            ->with('permissions')
            ->withCount('users')
            ->orderBy('name')
            ->get();

        return response()->json([
            'data' => $roles->map(fn (Role $role) => $this->presentRole($role))->all(),
        ]);
    }

    /** Remplace l'ensemble des permissions d'un rôle (synchronisation complète). */
    public function syncPermissions(Request $request, Role $role): JsonResponse
    {
        $data = $request->validate([
            'permissions' => ['present', 'array'],
            'permissions.*' => ['string', 'distinct', Rule::enum(PermissionName::class)],
        ]);

        $names = array_values(array_unique($data['permissions']));
        $ids = Permission::query()->whereIn('name', $names)->pluck('id')->all();

        // Les permissions sont seedées : toute valeur inconnue est un référentiel
        // désynchronisé, on refuse plutôt que de créer une permission fantôme.
        if (count($ids) !== count($names)) {
            throw ValidationException::withMessages([
                'permissions' => 'Une ou plusieurs permissions sont inconnues du référentiel.',
            ]);
        }

        $before = $role->permissions()->pluck('name')->all();

        $role->permissions()->sync($ids);
        $role->load('permissions')->loadCount('users');

        $after = $role->permissions->pluck('name')->all();

        // Aucun cas d'audit dédié aux rôles : on journalise sous RoleAssigned,
        // qui couvre déjà toute modification d'habilitation.
        $this->audit->log(AuditAction::RoleAssigned, $role, ['permissions' => $before], [
            'permissions' => $after,
        ], $request->user());

        return response()->json([
            'message' => 'Permissions du rôle mises à jour.',
            'data' => $this->presentRole($role),
        ]);
    }

    // ------------------------------------------------------------------ helpers

    /**
     * @return array<string, mixed>
     */
    private function presentRole(Role $role): array
    {
        return [
            'id' => $role->id,
            'name' => $role->name,
            'label' => $role->label,
            'description' => $role->description,
            'is_system' => (bool) $role->is_system,
            'users_count' => (int) ($role->users_count ?? 0),
            'permissions' => $role->permissions
                ->map(fn (Permission $permission) => [
                    'name' => $permission->name,
                    'label' => $permission->label,
                    'group' => $permission->group,
                ])
                ->values()
                ->all(),
        ];
    }
}
