<?php

namespace App\Console\Commands;

use App\Models\PaymentAttempt;
use Illuminate\Console\Command;

/** Nombre de tentatives de paiement journalisées pour une commande (diagnostic). */
class AttemptCount extends Command
{
    protected $signature = 'tombola:attempt-count {reference : Référence de la commande}';

    protected $description = 'Affiche le nombre de tentatives de paiement journalisées pour une commande';

    public function handle(): int
    {
        $count = PaymentAttempt::query()
            ->whereHas('payment.order', fn ($query) => $query->where('reference', $this->argument('reference')))
            ->count();

        $this->info((string) $count);

        return self::SUCCESS;
    }
}
