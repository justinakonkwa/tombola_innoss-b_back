<?php

namespace App\Console\Commands;

use App\Enums\CampaignStatus;
use App\Models\Campaign;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Réinitialise le jeu de démonstration.
 *
 * Le tirage clôture légitimement la campagne : sans remise à zéro, les scripts
 * de vérification ne sont plus rejouables. Cette commande remet la campagne de
 * démonstration en vente et purge les données transactionnelles associées.
 *
 * Refusée en production. Le journal d'audit n'est jamais purgé : la trace des
 * opérations reste consultable même après réinitialisation.
 */
class DemoReset extends Command
{
    protected $signature = 'tombola:demo-reset {--campaign=lamborghini : Slug de la campagne à réinitialiser}';

    protected $description = 'Réinitialise le jeu de démonstration (hors production)';

    /** Tables transactionnelles purgées, dans l'ordre des dépendances. */
    private const TABLES = [
        'winner' => 'winners',
        'draw_entry' => 'draw_entries',
        'draw' => 'draws',
        'refund' => 'refunds',
        'payment_attempt' => 'payment_attempts',
        'payment_webhook' => 'payment_webhooks',
        'ticket' => 'tickets',
        'ticket_batch' => 'ticket_batches',
        'payment' => 'payments',
        'order' => 'orders',
    ];

    public function handle(): int
    {
        if (app()->environment('production')) {
            $this->error('Commande interdite en production.');

            return self::FAILURE;
        }

        $slug = (string) $this->option('campaign');

        /** @var Campaign|null $campaign */
        $campaign = Campaign::query()->where('slug', $slug)->first();

        if (! $campaign) {
            $this->error("Campagne introuvable : {$slug}");

            return self::FAILURE;
        }

        // TRUNCATE (et non DELETE) : les triggers d'immuabilité des tickets et
        // du snapshot de tirage interdisent à juste titre toute suppression
        // ligne à ligne — y compris pour un administrateur.
        DB::statement('TRUNCATE '.implode(', ', array_values(self::TABLES)).' RESTART IDENTITY CASCADE');

        foreach (['orders_reference_seq', 'draws_reference_seq'] as $sequence) {
            DB::statement("ALTER SEQUENCE {$sequence} RESTART WITH 1");
        }

        $campaign->forceFill([
            'status' => CampaignStatus::Active,
            'tickets_sold' => 0,
            'tickets_reserved' => 0,
        ])->save();

        foreach ($campaign->prizes as $prize) {
            $prize->forceFill(['quantity_awarded' => 0])->save();
        }

        $this->info("Jeu de démonstration réinitialisé (campagne « {$campaign->name} » remise en vente).");
        $this->line('Le journal d’audit est conservé.');

        return self::SUCCESS;
    }
}
