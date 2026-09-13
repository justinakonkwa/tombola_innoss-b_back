<?php

namespace App\Console\Commands;

use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\Payment;
use Illuminate\Console\Command;

/**
 * Crée un paiement de test rattaché à une commande existante.
 *
 * Utile pour rejouer le parcours webhook → tickets sans passer par la
 * passerelle (tests d'intégration, démonstration, support). Réservé aux
 * environnements non-production.
 */
class CreateTestPayment extends Command
{
    protected $signature = 'tombola:test-payment
                            {reference : Référence de la commande (ORD-…)}
                            {provider_payment_id : Identifiant public à simuler côté passerelle}
                            {--status=pending : Statut initial du paiement}';

    protected $description = 'Crée un paiement de test pour une commande (hors production)';

    public function handle(): int
    {
        if (app()->environment('production')) {
            $this->error('Commande interdite en production.');

            return self::FAILURE;
        }

        $order = Order::query()->where('reference', $this->argument('reference'))->first();

        if (! $order) {
            $this->error("Commande introuvable : {$this->argument('reference')}");

            return self::FAILURE;
        }

        $status = PaymentStatus::tryFrom((string) $this->option('status')) ?? PaymentStatus::Pending;

        $payment = Payment::query()->updateOrCreate(
            ['provider' => 'futaye', 'provider_payment_id' => $this->argument('provider_payment_id')],
            [
                'order_id' => $order->id,
                'provider_reference' => 'TEST-'.strtoupper(bin2hex(random_bytes(4))),
                'channel' => 'mobile_money',
                'amount' => $order->total_amount,
                'currency' => $order->currency,
                'status' => $status,
            ]
        );

        // Le paiement doit correspondre au montant de la commande, sinon le
        // webhook sera légitimement rejeté par le contrôle de cohérence.
        $this->info($payment->id);

        return self::SUCCESS;
    }
}
