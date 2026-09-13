<?php

namespace App\Services;

use App\Enums\AuditAction;
use App\Enums\TicketStatus;
use App\Exceptions\TicketIssuanceException;
use App\Models\Campaign;
use App\Models\Payment;
use App\Models\Ticket;
use App\Models\TicketBatch;
use Illuminate\Support\Facades\DB;

/**
 * Émission des tickets (cahier des charges §7, §9, §10, §47).
 *
 * Invariants garantis ici ET en base :
 *  - un paiement confirmé = au plus UN lot de tickets (index unique ticket_batches.payment_id) ;
 *  - aucun ticket n'est créé sans paiement au statut success ;
 *  - la numérotation vient d'une séquence PostgreSQL : impossible à manipuler côté client ;
 *  - la capacité de la campagne est vérifiée sous verrou (FOR UPDATE) : pas de survente.
 */
class TicketService
{
    public function __construct(
        private readonly AuditService $audit,
        private readonly NotificationService $notifications,
    ) {
    }

    /**
     * Attribue les tickets d'un paiement confirmé. Idempotent : un second appel
     * (double webhook, retry, réconciliation) renvoie le lot déjà créé.
     */
    public function issueForPayment(Payment $payment): TicketBatch
    {
        return DB::transaction(function () use ($payment) {
            /** @var Payment $locked */
            $locked = Payment::query()->whereKey($payment->getKey())->lockForUpdate()->firstOrFail();

            // Idempotence n°1 : le lot existe déjà.
            if ($existing = TicketBatch::query()->where('payment_id', $locked->id)->first()) {
                return $existing;
            }

            if (! $locked->isSettled()) {
                throw new TicketIssuanceException(
                    "Aucun ticket ne peut être émis : le paiement {$locked->id} n'est pas confirmé."
                );
            }

            /** @var \App\Models\Order $order */
            $order = $locked->order()->lockForUpdate()->firstOrFail();

            /** @var Campaign $campaign */
            $campaign = Campaign::query()->whereKey($order->campaign_id)->lockForUpdate()->firstOrFail();

            $quantity = (int) $order->quantity;

            if ($quantity < 1) {
                throw new TicketIssuanceException('Quantité de tickets invalide.');
            }

            // Capacité : on tient compte des tickets déjà vendus et réservés.
            $alreadyAllocated = (int) $campaign->tickets_sold + (int) $campaign->tickets_reserved;
            if ($alreadyAllocated + $quantity > (int) $campaign->max_tickets) {
                throw new TicketIssuanceException('Capacité de la campagne dépassée : émission refusée.');
            }

            $serials = $this->nextSerials($quantity);
            $year = (int) now()->format('Y');

            $batch = TicketBatch::query()->create([
                'order_id' => $order->id,
                'payment_id' => $locked->id,
                'campaign_id' => $campaign->id,
                'user_id' => $order->user_id,
                'quantity' => $quantity,
                'first_serial' => min($serials),
                'last_serial' => max($serials),
                'checksum' => hash('sha256', $locked->id.'|'.implode(',', $serials)),
                'numbers' => array_map(fn (int $s) => $this->formatNumber($s, $year), $serials),
                'generated_at' => now(),
            ]);

            $now = now();
            $rows = [];

            foreach ($serials as $serial) {
                $rows[] = [
                    'id' => (string) \Illuminate\Support\Str::uuid(),
                    'ticket_number' => $this->formatNumber($serial, $year),
                    'serial' => $serial,
                    'batch_id' => $batch->id,
                    'user_id' => $order->user_id,
                    'campaign_id' => $campaign->id,
                    'order_id' => $order->id,
                    'payment_id' => $locked->id,
                    'status' => TicketStatus::Valid->value,
                    'qr_token' => bin2hex(random_bytes(24)),
                    'is_locked' => true,
                    'issued_at' => $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            Ticket::query()->insert($rows);

            // Compteurs de campagne : la réservation devient une vente ferme.
            $campaign->tickets_sold = (int) $campaign->tickets_sold + $quantity;
            $campaign->tickets_reserved = max(0, (int) $campaign->tickets_reserved - $quantity);
            $campaign->save();

            $this->audit->log(
                AuditAction::TicketsIssued,
                $batch,
                [],
                [
                    'payment_id' => $locked->id,
                    'order_id' => $order->id,
                    'quantity' => $quantity,
                    'first_number' => $batch->numbers[0] ?? null,
                    'last_number' => $batch->numbers[count($batch->numbers) - 1] ?? null,
                ],
            );

            return $batch;
        });
    }

    /**
     * Annule les tickets d'un lot (remboursement, fraude confirmée).
     * Les tickets ne sont jamais supprimés : la base l'interdit.
     */
    public function cancelBatch(TicketBatch $batch, string $reason): int
    {
        return DB::transaction(function () use ($batch, $reason) {
            $tickets = Ticket::query()
                ->where('batch_id', $batch->id)
                ->whereIn('status', [TicketStatus::Valid->value, TicketStatus::Pending->value, TicketStatus::Suspended->value])
                ->get();

            $cancelled = 0;

            foreach ($tickets as $ticket) {
                $ticket->forceFill([
                    'status' => TicketStatus::Cancelled,
                    'cancelled_at' => now(),
                    'cancellation_reason' => $reason,
                    'is_locked' => false,
                ])->save();
                $cancelled++;
            }

            if ($cancelled > 0) {
                $campaign = Campaign::query()->whereKey($batch->campaign_id)->lockForUpdate()->first();
                if ($campaign) {
                    $campaign->tickets_sold = max(0, (int) $campaign->tickets_sold - $cancelled);
                    $campaign->save();
                }

                $this->audit->log(AuditAction::TicketCancelled, $batch, [], [
                    'cancelled' => $cancelled,
                    'reason' => $reason,
                ]);
            }

            return $cancelled;
        });
    }

    /**
     * Réserve des tickets pour une commande en attente de paiement.
     * Empêche la survente pendant la fenêtre de paiement.
     */
    public function reserve(Campaign $campaign, int $quantity): void
    {
        DB::transaction(function () use ($campaign, $quantity) {
            /** @var Campaign $locked */
            $locked = Campaign::query()->whereKey($campaign->getKey())->lockForUpdate()->firstOrFail();

            if (! $locked->acceptsSales()) {
                throw new TicketIssuanceException('Cette campagne n’accepte plus de participations.');
            }

            if ($locked->ticketsRemaining() < $quantity) {
                throw new TicketIssuanceException('Nombre de tickets disponibles insuffisant.');
            }

            $locked->tickets_reserved = (int) $locked->tickets_reserved + $quantity;
            $locked->save();
            $campaign->setRawAttributes($locked->getAttributes(), true);
        });
    }

    /** Libère une réservation (échec, annulation ou expiration de la commande). */
    public function release(Campaign $campaign, int $quantity): void
    {
        DB::transaction(function () use ($campaign, $quantity) {
            /** @var Campaign $locked */
            $locked = Campaign::query()->whereKey($campaign->getKey())->lockForUpdate()->first();
            if (! $locked) {
                return;
            }

            $locked->tickets_reserved = max(0, (int) $locked->tickets_reserved - $quantity);
            $locked->save();
        });
    }

    /**
     * @return list<int>
     */
    private function nextSerials(int $quantity): array
    {
        $rows = DB::select(
            'SELECT nextval(\'tickets_serial_seq\') AS serial FROM generate_series(1, ?)',
            [$quantity]
        );

        return array_map(fn ($row) => (int) $row->serial, $rows);
    }

    public function formatNumber(int $serial, ?int $year = null): string
    {
        return sprintf('TMB-%d-%08d', $year ?? (int) now()->format('Y'), $serial);
    }
}
