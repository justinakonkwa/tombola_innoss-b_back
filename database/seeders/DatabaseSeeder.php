<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Amorçage complet de la plateforme.
 *
 * L'ordre est important :
 *  1. roles/permissions, car les autres seeders s'y rattachent ;
 *  2. le super administrateur, qui référence le rôle super_admin ;
 *  3. les paramètres applicatifs ;
 *  4. les campagnes, dont `created_by` pointe vers l'administrateur.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            RolePermissionSeeder::class,
            AdminUserSeeder::class,
            SettingSeeder::class,
            CampaignSeeder::class,
        ]);
    }
}
