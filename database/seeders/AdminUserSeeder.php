<?php

namespace Database\Seeders;

use App\Enums\RoleName;
use App\Enums\UserStatus;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Compte super administrateur de démarrage.
 *
 * Idempotent : le compte est identifié par son e-mail et simplement mis à jour
 * s'il existe déjà. Les identifiants ne sont affichés qu'à la création, pour ne
 * pas laisser fuiter le mot de passe dans les journaux de déploiement.
 *
 * SÉCURITÉ : le mot de passe par défaut de développement n'est utilisé QUE dans
 * les environnements locaux. Ailleurs, un mot de passe explicite est exigé ou,
 * à défaut, un mot de passe aléatoire est généré et affiché une seule fois.
 * Le dépôt étant public, un mot de passe par défaut connu de tous ne doit
 * jamais pouvoir se retrouver sur une instance de production.
 */
class AdminUserSeeder extends Seeder
{
    /** Mot de passe de développement, réservé aux environnements locaux. */
    private const DEV_PASSWORD = 'Tombola++2026*';

    public function run(): void
    {
        $email = (string) env('TOMBOLA_ADMIN_EMAIL', 'admin@tombola-innossb.cd');
        $password = $this->resolvePassword();

        if ($password === null) {
            if ($this->command) {
                $this->command->error(
                    'TOMBOLA_ADMIN_PASSWORD est obligatoire hors environnement local. '
                    .'Aucun compte administrateur n’a été créé.'
                );
            }

            return;
        }

        /** @var User|null $user */
        $user = User::query()->where('email', $email)->first();
        $created = $user === null;

        if ($created) {
            $user = new User();
            $user->email = $email;
        }

        $user->forceFill([
            'first_name' => 'Admin',
            'last_name' => 'Tombola',
            'phone' => '243820000000',
            'password' => $password['value'], // haché automatiquement (cast `hashed`)
            'country' => 'CD',
            'status' => UserStatus::Active,
            'is_admin' => true,
            'email_verified_at' => now(),
            'failed_login_attempts' => 0,
            'locked_until' => null,
        ])->save();

        $role = Role::query()->where('name', RoleName::SuperAdmin->value)->first();

        if ($role) {
            // `syncWithoutDetaching` : conserve d'éventuels autres rôles déjà attribués.
            $user->roles()->syncWithoutDetaching([$role->id]);
        } elseif ($this->command) {
            $this->command->warn('Rôle super_admin introuvable : lancez RolePermissionSeeder avant AdminUserSeeder.');
        }

        if ($created && $this->command) {
            $this->command->info('Super administrateur créé.');
            $this->command->line("  E-mail       : {$email}");

            if ($password['generated']) {
                $this->command->line("  Mot de passe : {$password['value']}");
                $this->command->warn('Mot de passe généré aléatoirement : notez-le, il ne sera plus affiché.');
            } else {
                $this->command->line('  Mot de passe : (celui fourni dans TOMBOLA_ADMIN_PASSWORD)');
            }

            $this->command->line('  Activez la double authentification après la première connexion.');
        }
    }

    /**
     * @return array{value: string, generated: bool}|null
     */
    private function resolvePassword(): ?array
    {
        $configured = trim((string) env('TOMBOLA_ADMIN_PASSWORD', ''));

        if ($configured !== '') {
            if (strlen($configured) < 12) {
                if ($this->command) {
                    $this->command->error('TOMBOLA_ADMIN_PASSWORD doit contenir au moins 12 caractères.');
                }

                return null;
            }

            return ['value' => $configured, 'generated' => false];
        }

        // Hors développement, on n'impose jamais un mot de passe connu du dépôt.
        if (app()->environment('local', 'testing')) {
            return ['value' => self::DEV_PASSWORD, 'generated' => false];
        }

        return ['value' => $this->generatePassword(), 'generated' => true];
    }

    private function generatePassword(): string
    {
        $alphabet = 'abcdefghijkmnopqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $password = '';

        for ($i = 0; $i < 24; $i++) {
            $password .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        return $password;
    }
}
