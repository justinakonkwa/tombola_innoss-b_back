<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\AuditService;
use App\Services\SecurityEventService;
use Illuminate\Console\Command;

/**
 * Réinitialise la double authentification d'un compte.
 *
 * Cas d'usage : un membre du personnel a perdu son téléphone et ne peut plus
 * se connecter au back-office. L'opération exige un accès shell au serveur
 * (niveau de privilège adéquat), laisse une trace d'audit et un événement de
 * sécurité de niveau élevé — un détournement de compte est ainsi détectable.
 */
class ResetMfa extends Command
{
    protected $signature = 'tombola:reset-mfa
                            {email : Adresse e-mail du compte}
                            {--force : Autorise l’opération en production}';

    protected $description = 'Désactive la double authentification d’un compte (perte d’appareil)';

    public function handle(AuditService $audit, SecurityEventService $security): int
    {
        if (app()->environment('production') && ! $this->option('force')) {
            $this->error('En production, confirmez explicitement avec --force.');

            return self::FAILURE;
        }

        $email = mb_strtolower((string) $this->argument('email'));

        /** @var User|null $user */
        $user = User::query()->where('email', $email)->first();

        if (! $user) {
            $this->error("Aucun compte pour {$email}.");

            return self::FAILURE;
        }

        if (! $user->hasMfaEnabled()) {
            $this->info('La double authentification n’était pas activée sur ce compte.');

            return self::SUCCESS;
        }

        $before = ['totp_secret' => '***', 'totp_confirmed_at' => $user->totp_confirmed_at?->toIso8601String()];

        $user->forceFill(['totp_secret' => null, 'totp_confirmed_at' => null])->save();

        $security->log('mfa_reset_by_operator', 'high', $user, [
            'operator' => get_current_user() ?: 'cli',
            'host' => gethostname() ?: null,
        ], 'Double authentification réinitialisée depuis la ligne de commande.');

        $audit->log('MFA_RESET', $user, $before, ['totp_confirmed_at' => null]);

        $this->info("Double authentification réinitialisée pour {$email}.");
        $this->line('Le compte devra réenrôler un nouveau secret à la prochaine connexion.');

        return self::SUCCESS;
    }
}
