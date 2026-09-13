<?php

namespace App\Console\Commands;

use App\Models\TicketBatch;
use Illuminate\Console\Command;

/** Affiche le nombre de lots de tickets émis pour une commande (diagnostic). */
class OrderSummary extends Command
{
    protected $signature = 'tombola:order-summary {reference : Référence de la commande}';

    protected $description = 'Affiche le nombre de lots de tickets associés à une commande';

    public function handle(): int
    {
        $count = TicketBatch::query()
            ->whereHas('order', fn ($query) => $query->where('reference', $this->argument('reference')))
            ->count();

        $this->info((string) $count);

        return self::SUCCESS;
    }
}
