<?php

namespace App\Console\Commands;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Services\TicketService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Ferme les commandes non payées arrivées à expiration et libère les tickets
 * réservés, pour qu'ils redeviennent disponibles à la vente.
 */
class ExpireOrders extends Command
{
    protected $signature = 'tombola:expire-orders {--limit=500 : Nombre maximal de commandes traitées}';

    protected $description = 'Expire les commandes non payées et libère les réservations de tickets';

    public function handle(TicketService $tickets): int
    {
        $orders = Order::query()
            ->whereIn('status', [OrderStatus::Pending->value, OrderStatus::Processing->value])
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', now())
            ->limit((int) $this->option('limit'))
            ->get();

        $expired = 0;

        foreach ($orders as $order) {
            DB::transaction(function () use ($order, $tickets, &$expired) {
                /** @var Order $locked */
                $locked = Order::query()->whereKey($order->getKey())->lockForUpdate()->first();

                if (! $locked || $locked->isPaid()) {
                    return;
                }

                $locked->forceFill(['status' => OrderStatus::Expired])->save();

                if ($locked->campaign) {
                    $tickets->release($locked->campaign, (int) $locked->quantity);
                }

                $expired++;
            });
        }

        $this->info("Commandes expirées : {$expired}");

        return self::SUCCESS;
    }
}
