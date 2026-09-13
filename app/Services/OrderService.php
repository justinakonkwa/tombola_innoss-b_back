<?php

namespace App\Services;

use App\Enums\AuditAction;
use App\Enums\OrderStatus;
use App\Enums\RiskAction;
use App\Exceptions\OrderException;
use App\Models\Campaign;
use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Création des commandes (cahier des charges §8, §14, §38).
 *
 * Règle absolue : le prix, la quantité et le montant total sont calculés et
 * validés côté serveur. Un client ne peut jamais imposer un prix ou un statut.
 */
class OrderService
{
    public function __construct(
        private readonly TicketService $tickets,
        private readonly RiskService $risk,
        private readonly AuditService $audit,
    ) {
    }

    /**
     * @param  array{idempotency_key?: string|null, ip?: string|null, user_agent?: string|null}  $context
     */
    public function create(User $user, Campaign $campaign, int $quantity, array $context = []): Order
    {
        if ($quantity < 1) {
            throw new OrderException('Le nombre de tickets doit être au moins égal à 1.');
        }

        // Idempotence : rejouer la même requête ne crée pas de seconde commande.
        $idempotencyKey = $context['idempotency_key'] ?? null;
        if ($idempotencyKey) {
            $existing = Order::query()
                ->where('user_id', $user->id)
                ->where('idempotency_key', $idempotencyKey)
                ->first();

            if ($existing) {
                return $existing;
            }
        }

        return DB::transaction(function () use ($user, $campaign, $quantity, $context, $idempotencyKey) {
            /** @var Campaign $locked */
            $locked = Campaign::query()->whereKey($campaign->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->status->value !== \App\Enums\CampaignStatus::Active->value) {
                throw new OrderException('Cette campagne ne prend pas de participations actuellement.');
            }

            if ($locked->starts_at && $locked->starts_at->isFuture()) {
                throw new OrderException('Les ventes ne sont pas encore ouvertes.');
            }

            if ($locked->ends_at && $locked->ends_at->isPast()) {
                throw new OrderException('Les ventes sont closes.');
            }

            if ($quantity < (int) $locked->min_tickets_per_order) {
                throw new OrderException("Le minimum est de {$locked->min_tickets_per_order} ticket(s) par commande.");
            }

            if ($quantity > (int) $locked->max_tickets_per_order) {
                throw new OrderException("Le maximum est de {$locked->max_tickets_per_order} ticket(s) par commande.");
            }

            if ($locked->max_tickets_per_user !== null) {
                $owned = (int) DB::table('tickets')
                    ->where('user_id', $user->id)
                    ->where('campaign_id', $locked->id)
                    ->count();

                $pending = (int) Order::query()
                    ->where('user_id', $user->id)
                    ->where('campaign_id', $locked->id)
                    ->whereIn('status', [OrderStatus::Pending->value, OrderStatus::Processing->value])
                    ->sum('quantity');

                if ($owned + $pending + $quantity > (int) $locked->max_tickets_per_user) {
                    throw new OrderException(
                        "Vous ne pouvez pas détenir plus de {$locked->max_tickets_per_user} tickets pour cette campagne."
                    );
                }
            }

            // Évaluation anti-fraude avant d'engager la réservation.
            $assessment = $this->risk->assess($user, null, [
                'ip' => $context['ip'] ?? null,
                'quantity' => $quantity,
            ]);

            if ($assessment->action === RiskAction::Block) {
                throw new OrderException(
                    'Votre commande nécessite une vérification manuelle. Notre équipe vous contactera.'
                );
            }

            // Réservation : empêche la survente pendant la fenêtre de paiement.
            $this->tickets->reserve($locked, $quantity);

            // Prix figé côté serveur, jamais fourni par le client.
            $unitPrice = (string) $locked->ticket_price;
            $total = bcmul($unitPrice, (string) $quantity, 2);

            $sequence = (int) DB::selectOne("SELECT nextval('orders_reference_seq') AS n")->n;

            $order = Order::query()->create([
                'reference' => sprintf('ORD-%d-%06d', (int) now()->format('Y'), $sequence),
                'user_id' => $user->id,
                'campaign_id' => $locked->id,
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'total_amount' => $total,
                'currency' => $locked->currency,
                'status' => OrderStatus::Pending,
                'idempotency_key' => $idempotencyKey,
                'ip' => $context['ip'] ?? null,
                'user_agent' => mb_substr((string) ($context['user_agent'] ?? ''), 0, 255),
                'risk_score' => $assessment->score,
                'expires_at' => now()->addMinutes((int) config('tombola.orders.ttl_minutes', 30)),
            ]);

            $assessment->forceFill(['order_id' => $order->id])->save();

            $this->audit->log(AuditAction::OrderCreated, $order, [], [
                'reference' => $order->reference,
                'campaign_id' => $locked->id,
                'quantity' => $quantity,
                'total_amount' => $total,
                'risk_score' => $assessment->score,
            ], $user);

            return $order->refresh();
        });
    }

    /** Annule une commande non payée et libère la réservation. */
    public function cancel(Order $order, string $reason = 'user_cancelled'): Order
    {
        return DB::transaction(function () use ($order, $reason) {
            /** @var Order $locked */
            $locked = Order::query()->whereKey($order->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->isPaid()) {
                throw new OrderException('Une commande payée ne peut pas être annulée : demandez un remboursement.');
            }

            if ($locked->status === OrderStatus::Cancelled) {
                return $locked;
            }

            $locked->forceFill(['status' => OrderStatus::Cancelled])->save();

            if ($locked->campaign) {
                $this->tickets->release($locked->campaign, (int) $locked->quantity);
            }

            $this->audit->log(AuditAction::OrderCreated, $locked, [], ['cancelled' => true, 'reason' => $reason]);

            return $locked->refresh();
        });
    }
}
