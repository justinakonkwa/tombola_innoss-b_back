<?php

namespace Database\Seeders;

use App\Enums\PermissionName;
use App\Enums\RoleName;
use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;

/**
 * Référentiel RBAC (cahier des charges §15).
 *
 * Idempotent : les rôles et permissions sont créés/mis à jour puis les
 * associations sont synchronisées (`sync`), ce qui rend le seeder rejouable
 * sans créer de doublon ni laisser de permission orpheline.
 */
class RolePermissionSeeder extends Seeder
{
    /**
     * Permissions explicitement retirées au rôle `admin` : ce sont les
     * prérogatives réservées au super administrateur (gouvernance, sécurité).
     *
     * (Méthode et non constante : PHP 8.1 n'autorise pas `Enum::Case->value`
     * dans une expression constante.)
     *
     * @return list<string>
     */
    private function adminExcluded(): array
    {
        return [
            PermissionName::RolesManage->value,
            PermissionName::SettingsManage->value,
            PermissionName::SecurityManage->value,
            PermissionName::RefundsApprove->value,
        ];
    }

    /** @return array<string, string> */
    private function descriptions(): array
    {
        return [
            RoleName::SuperAdmin->value => 'Accès total, y compris la gouvernance des rôles, des paramètres et de la sécurité.',
            RoleName::Admin->value => 'Gestion opérationnelle complète (campagnes, tickets, paiements, tirages) hors gouvernance.',
            RoleName::Finance->value => 'Pilotage financier : paiements, réconciliation, remboursements et rapports.',
            RoleName::Support->value => 'Assistance aux participants : comptes, tickets et traitement des alertes.',
            RoleName::Auditor->value => 'Lecture seule : consultation des campagnes, tickets, paiements, tirages et journaux.',
            RoleName::Participant->value => 'Participant public : aucun accès au back-office.',
        ];
    }

    public function run(): void
    {
        // 1. Toutes les permissions de l'énumération.
        foreach (PermissionName::cases() as $permission) {
            Permission::query()->updateOrCreate(
                ['name' => $permission->value],
                [
                    'label' => $permission->label(),
                    'group' => $permission->group(),
                ]
            );
        }

        $permissionIds = Permission::query()->pluck('id', 'name');
        $all = array_map(fn (PermissionName $permission) => $permission->value, PermissionName::cases());

        // 2. Matrice rôle → permissions.
        $matrix = [
            RoleName::SuperAdmin->value => $all,
            RoleName::Admin->value => array_values(array_diff($all, $this->adminExcluded())),
            RoleName::Finance->value => [
                PermissionName::PaymentsView->value,
                PermissionName::PaymentsReconcile->value,
                PermissionName::RefundsRequest->value,
                PermissionName::RefundsApprove->value,
                PermissionName::FinanceReports->value,
                PermissionName::AuditView->value,
                PermissionName::UsersView->value,
                PermissionName::TicketsView->value,
                PermissionName::CampaignsView->value,
            ],
            RoleName::Support->value => [
                PermissionName::UsersView->value,
                PermissionName::UsersManage->value,
                PermissionName::TicketsView->value,
                PermissionName::TicketsCancel->value,
                PermissionName::CampaignsView->value,
                PermissionName::NotificationsSend->value,
                PermissionName::RiskReview->value,
            ],
            RoleName::Auditor->value => [
                PermissionName::CampaignsView->value,
                PermissionName::TicketsView->value,
                PermissionName::PaymentsView->value,
                PermissionName::DrawsView->value,
                PermissionName::AuditView->value,
                PermissionName::SecurityView->value,
                PermissionName::UsersView->value,
                PermissionName::FinanceReports->value,
            ],
            RoleName::Participant->value => [],
        ];

        $descriptions = $this->descriptions();

        foreach (RoleName::cases() as $roleName) {
            $role = Role::query()->updateOrCreate(
                ['name' => $roleName->value],
                [
                    'label' => $roleName->label(),
                    'description' => $descriptions[$roleName->value] ?? null,
                    'is_system' => true,
                ]
            );

            $ids = array_values(array_filter(array_map(
                fn (string $name) => $permissionIds[$name] ?? null,
                $matrix[$roleName->value] ?? []
            )));

            // `sync` garantit qu'un rejeu ne laisse ni doublon ni permission résiduelle.
            $role->permissions()->sync($ids);
        }
    }
}
