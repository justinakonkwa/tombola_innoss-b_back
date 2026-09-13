<?php

namespace App\Console\Commands;

use App\Enums\PaymentStatus;
use App\Models\Payment;
use App\Services\PaymentService;
use Illuminate\Console\Command;

/**
 * Filet de sécurité si un webhook a été perdu : interroge la passerelle et
 * applique le statut réel. Un paiement confirmé côté opérateur mais jamais
 * notifié déclenche l'émission des tickets.
 */
class ReconcilePayments extends Command
{
    protected $signature = 'tombola:reconcile-payments
                            {--minutes=30 : Fenêtre d’âge minimal des paiements à vérifier}
                            {--limit=200 : Nombre maximal de paiements traités}';

    protected $description = 'Réconcilie auprès de la passerelle les paiements restés en attente';

    public function handle(PaymentService $payments): int
    {
        $candidates = Payment::query()
            ->whereIn('status', [PaymentStatus::Created->value, PaymentStatus::Pending->value])
            ->whereNotNull('provider_payment_id')
            ->where('created_at', '<=', now()->subMinutes((int) $this->option('minutes')))
            ->where('created_at', '>=', now()->subDays(7))
            ->limit((int) $this->option('limit'))
            ->get();

        $checked = 0;
        $confirmed = 0;
        $failed = 0;

        foreach ($candidates as $payment) {
            $before = $payment->status;
            $updated = $payments->reconcile($payment);
            $checked++;

            if ($before !== PaymentStatus::Success && $updated->status === PaymentStatus::Success) {
                $confirmed++;
                $this->line("Paiement {$updated->id} confirmé par réconciliation.");
            } elseif ($updated->status === PaymentStatus::Failed) {
                $failed++;
            }
        }

        $this->info("Vérifiés : {$checked} — confirmés : {$confirmed} — échoués : {$failed}");

        return self::SUCCESS;
    }
}
