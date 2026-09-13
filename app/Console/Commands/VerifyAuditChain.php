<?php

namespace App\Console\Commands;

use App\Services\AuditService;
use Illuminate\Console\Command;

/**
 * Vérifie l'intégrité du journal d'audit. Toute rupture de chaîne signale une
 * tentative d'altération ou de suppression et doit déclencher une alerte.
 */
class VerifyAuditChain extends Command
{
    protected $signature = 'tombola:verify-audit-chain';

    protected $description = 'Vérifie l’intégrité de la chaîne du journal d’audit';

    public function handle(AuditService $audit): int
    {
        $result = $audit->verifyChain();

        if ($result['valid']) {
            $this->info("Chaîne d’audit intègre — {$result['checked']} entrée(s) vérifiée(s).");

            return self::SUCCESS;
        }

        $this->error('CHAÎNE D’AUDIT ROMPUE — alerte de sécurité.');

        return self::FAILURE;
    }
}
