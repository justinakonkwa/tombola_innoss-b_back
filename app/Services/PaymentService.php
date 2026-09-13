<?php

namespace App\Services;

use App\Enums\AuditAction;
use App\Enums\PaymentChannel;
use App\Enums\PaymentStatus;
use App\Enums\WebhookStatus;
use App\Exceptions\PaymentProviderException;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentAttempt;
use App\Models\PaymentWebhook;
use App\Enums\OrderStatus;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Cycle de vie des paiements (cahier des charges §8–§11, §38).
 *
 * Principes non négociables :
 *  - le frontend ne décide jamais qu'un paiement est réussi ;
 *  - le montant attendu est recalculé côté serveur puis comparé au montant confirmé
 *    par la passerelle : toute divergence rejette le webhook et lève une alerte ;
 *  - les webhooks sont authentifiés (HMAC), anti-rejeu (horodatage + hash de payload)
 *    et idempotents (une seule confirmation, un seul lot de tickets).
 */
class PaymentService
{
    public function __construct(
        private readonly FutayeClient $futaye,
        private readonly TicketService $tickets,
        private readonly AuditService $audit,
        private readonly SecurityEventService $security,
        private readonly NotificationService $notifications,
    ) {
    }

    /**
     * Ouvre une session de paiement auprès de la passerelle.
     *
     * @param  array{channel?: string, phone?: string, return_url?: string}  $options
     */
    public function initiate(Order $order, array $options = []): Payment
    {
        return DB::transaction(function () use ($order, $options) {
            /** @var Order $locked */
            $locked = Order::query()->whereKey($order->getKey())->lockForUpdate()->firstOrFail();

            if (! $locked->isPayable()) {
                throw new PaymentProviderException('Cette commande n’est plus payable.');
            }

            // Réutilisation d'une session déjà ouverte pour éviter les doublons.
            $existing = Payment::query()
                ->where('order_id', $locked->id)
                ->whereIn('status', [PaymentStatus::Created->value, PaymentStatus::Pending->value])
                ->whereNotNull('checkout_url')
                ->latest('created_at')
                ->first();

            if ($existing !== null && $existing->created_at->gt(now()->subMinutes(20))) {
                return $existing;
            }

            $payment = Payment::query()->create([
                'order_id' => $locked->id,
                'provider' => 'futaye',
                // Le montant vient de la commande, jamais du client.
                'amount' => $locked->total_amount,
                'currency' => $locked->currency,
                'channel' => $this->resolveChannel($options),
                'operator' => $options['operator'] ?? null,
                'payer_phone' => $options['phone'] ?? null,
                'status' => PaymentStatus::Created,
            ]);

            $payload = [
                'amount' => number_format((float) $locked->total_amount, 2, '.', ''),
                'currency' => $locked->currency,
                'description' => sprintf('Tombola Innoss’B — %s (%d ticket(s))', $locked->campaign->name ?? 'Campagne', $locked->quantity),
                'returnUrl' => $options['return_url'] ?? config('services.futaye.return_url'),
            ];

            if (! empty($options['phone'])) {
                $payload['phone'] = $options['phone'];
                $payload['channel'] = $options['channel'] ?? PaymentChannel::MobileMoney->value;
            } elseif (($options['channel'] ?? null) === PaymentChannel::Card->value) {
                $payload['channel'] = PaymentChannel::Card->value;
            }

            $startedAt = microtime(true);

            try {
                $response = $this->futaye->createPayment($payload);
            } catch (PaymentProviderException $e) {
                $this->recordAttempt($payment, 'create', 'error', null, $payload, null, $e->getMessage(), $startedAt);
                $payment->forceFill(['status' => PaymentStatus::Failed])->save();

                throw $e;
            }

            $json = $response['json'] ?? [];
            $duration = (int) round((microtime(true) - $startedAt) * 1000);

            $this->recordAttempt(
                $payment,
                'create',
                $response['ok'] ? 'success' : 'failed',
                $response['status'],
                $payload,
                $json,
                $response['ok'] ? null : mb_substr($response['body'], 0, 500),
                $duration
            );

            if (! $response['ok']) {
                $payment->forceFill(['status' => PaymentStatus::Failed, 'provider_payload' => $json])->save();

                throw new PaymentProviderException('La passerelle de paiement a refusé la création de la session.');
            }

            $payment->forceFill([
                'provider_payment_id' => $this->extractPaymentId($json),
                'provider_reference' => $json['reference'] ?? null,
                'checkout_url' => $this->extractCheckoutUrl($json),
                'status' => ($json['status'] ?? null) === 'PENDING' ? PaymentStatus::Pending : PaymentStatus::Created,
                'provider_payload' => $json,
            ])->save();

            $locked->forceFill(['status' => OrderStatus::Processing])->save();

            $this->audit->log(AuditAction::PaymentInitiated, $payment, [], [
                'order_reference' => $locked->reference,
                'amount' => (string) $payment->amount,
                'currency' => $payment->currency,
                'channel' => $payment->channel->value,
            ]);

            return $payment->refresh();
        });
    }

    /**
     * Traite un webhook entrant. Renvoie la ligne journalisée ; son statut
     * indique au contrôleur quelle réponse HTTP renvoyer.
     */
    public function handleWebhook(Request $request): PaymentWebhook
    {
        $rawBody = $request->getContent();
        $signature = (string) $request->header('X-Futaye-Signature', '');
        $timestamp = (string) $request->header('X-Futaye-Timestamp', '');
        $ip = $request->ip();

        // La signature est vérifiée AVANT toute écriture : un corps non signé ne
        // doit jamais pouvoir « consommer » l'empreinte d'un webhook légitime et
        // ainsi bloquer un paiement réel (déni de traitement).
        if (! $this->futaye->verifyWebhookSignature($rawBody, $timestamp, $signature)) {
            $this->security->log('webhook_signature_invalid', 'high', null, [
                'ip' => $ip,
                'signature' => mb_substr($signature, 0, 24),
                'timestamp' => $timestamp,
            ], 'Signature de webhook invalide ou horodatage hors tolérance.');

            // Les rejets sont journalisés dans un espace d'empreintes distinct :
            // ils ne peuvent ni entrer en collision avec un webhook valide, ni
            // servir de préemption sur le corps d'un paiement légitime.
            return $this->recordRejected($rawBody, $signature, $timestamp, $ip, 'Signature invalide ou horodatage hors tolérance.');
        }

        $payloadHash = hash('sha256', $rawBody);

        // Anti-rejeu / idempotence : le même corps ne peut être traité deux fois.
        if ($existing = PaymentWebhook::query()->where('payload_hash', $payloadHash)->first()) {
            $existing->forceFill(['status' => WebhookStatus::Duplicate])->save();

            return $existing;
        }

        $payload = json_decode($rawBody, true);
        if (! is_array($payload)) {
            return $this->recordRejected($rawBody, $signature, $timestamp, $ip, 'Corps JSON invalide.');
        }

        $webhook = PaymentWebhook::query()->create([
            'provider' => 'futaye',
            'event' => $payload['event'] ?? null,
            'provider_reference' => $payload['reference'] ?? null,
            'signature' => $signature,
            'timestamp_header' => $timestamp,
            'payload_hash' => $payloadHash,
            'payload' => $payload,
            'status' => WebhookStatus::Received,
            'ip' => $ip,
        ]);

        $this->applyWebhook($webhook);

        return $webhook->refresh();
    }

    /**
     * Journalise un webhook rejeté dans un espace d'empreintes dédié.
     *
     * Une empreinte de rejet intègre la signature et l'horodatage : elle ne peut
     * donc jamais entrer en collision avec l'empreinte d'un webhook légitime, et
     * un tiers ne peut pas préempter le corps d'un paiement réel en envoyant une
     * requête non signée portant le même contenu.
     */
    private function recordRejected(
        string $rawBody,
        string $signature,
        string $timestamp,
        ?string $ip,
        string $error,
    ): PaymentWebhook {
        $hash = hash('sha256', 'rejected|'.$rawBody.'|'.$signature.'|'.$timestamp);

        if ($existing = PaymentWebhook::query()->where('payload_hash', $hash)->first()) {
            return $existing;
        }

        try {
            return PaymentWebhook::query()->create([
                'provider' => 'futaye',
                'event' => null,
                'signature' => mb_substr($signature, 0, 255),
                'timestamp_header' => $timestamp,
                'payload_hash' => $hash,
                'payload' => ['raw' => mb_substr($rawBody, 0, 4000)],
                'status' => WebhookStatus::Rejected,
                'error' => $error,
                'ip' => $ip,
            ]);
        } catch (QueryException) {
            // Requêtes concurrentes : la trace existe déjà, on la renvoie.
            return PaymentWebhook::query()->where('payload_hash', $hash)->firstOrFail();
        }
    }

    /** Applique un webhook journalisé (signature déjà validée). */
    public function applyWebhook(PaymentWebhook $webhook): void
    {
        $payload = $webhook->payload ?? [];
        $event = (string) ($payload['event'] ?? '');

        try {
            $payment = $this->findPayment($payload);

            if ($payment === null) {
                $webhook->forceFill([
                    'status' => WebhookStatus::Ignored,
                    'error' => 'Paiement inconnu pour cette référence.',
                    'processed_at' => now(),
                ])->save();

                return;
            }

            // Vérification du montant et de la devise : une divergence est rejetée et signalée.
            if (! $this->amountMatches($payment, $payload)) {
                $webhook->forceFill([
                    'status' => WebhookStatus::Rejected,
                    'error' => sprintf(
                        'Montant incohérent : attendu %s %s, reçu %s %s.',
                        $payment->amount,
                        $payment->currency,
                        $payload['amount'] ?? '?',
                        $payload['currency'] ?? '?'
                    ),
                    'processed_at' => now(),
                ])->save();

                $this->security->log('webhook_amount_mismatch', 'critical', $payment->order?->user, [
                    'payment_id' => $payment->id,
                    'expected' => (string) $payment->amount,
                    'received' => (string) ($payload['amount'] ?? ''),
                    'payload' => $payload,
                ], 'Montant de webhook différent du montant attendu.');

                $this->audit->log(AuditAction::WebhookRejected, $payment, [], $payload);

                return;
            }

            match ($event) {
                'PAYMENT_SUCCESS' => $this->confirm($payment, $payload),
                'PAYMENT_FAILED' => $this->fail($payment, $payload),
                'PAYOUT_SENT' => $this->recordPayout($payment, $payload),
                default => null,
            };

            $webhook->forceFill([
                'status' => WebhookStatus::Processed,
                'processed_at' => now(),
                'error' => null,
            ])->save();
        } catch (\Throwable $e) {
            Log::error('Webhook Futaye: traitement impossible', [
                'webhook_id' => $webhook->id,
                'error' => $e->getMessage(),
            ]);

            $webhook->forceFill([
                'status' => WebhookStatus::Rejected,
                'error' => mb_substr($e->getMessage(), 0, 500),
                'processed_at' => now(),
            ])->save();
        }
    }

    /**
     * Confirme un paiement et déclenche l'émission des tickets.
     * Idempotent : si le paiement est déjà confirmé, aucun nouveau ticket n'est créé.
     */
    public function confirm(Payment $payment, array $payload = []): void
    {
        $batch = DB::transaction(function () use ($payment, $payload) {
            /** @var Payment $locked */
            $locked = Payment::query()->whereKey($payment->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->status === PaymentStatus::Success) {
                return null; // déjà confirmé : on ne réattribue rien
            }

            $locked->forceFill([
                'status' => PaymentStatus::Success,
                'confirmed_at' => now(),
                'provider_reference' => $payload['reference'] ?? $locked->provider_reference,
                'commission' => $payload['commission'] ?? $locked->commission,
                'net' => $payload['net'] ?? (float) $locked->amount - (float) ($payload['commission'] ?? $locked->commission),
                'provider_payload' => array_merge($locked->provider_payload ?? [], $payload),
            ])->save();

            $order = $locked->order()->lockForUpdate()->firstOrFail();
            $order->forceFill(['status' => OrderStatus::Paid, 'paid_at' => now()])->save();

            $this->audit->log(AuditAction::PaymentConfirmed, $locked, [], [
                'order_reference' => $order->reference,
                'amount' => (string) $locked->amount,
                'currency' => $locked->currency,
                'reference' => $locked->provider_reference,
            ]);

            return $this->tickets->issueForPayment($locked);
        });

        if ($batch !== null) {
            $this->notifications->paymentConfirmed($payment->refresh(), $batch);
        }
    }

    /** Marque un paiement comme échoué et libère la réservation de tickets. */
    public function fail(Payment $payment, array $payload = []): void
    {
        DB::transaction(function () use ($payment, $payload) {
            /** @var Payment $locked */
            $locked = Payment::query()->whereKey($payment->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->status === PaymentStatus::Success) {
                return; // un paiement confirmé ne redevient jamais un échec
            }

            $previous = $locked->status;
            $locked->forceFill([
                'status' => ($payload['status'] ?? null) === 'CANCELLED' ? PaymentStatus::Cancelled : PaymentStatus::Failed,
                'provider_payload' => array_merge($locked->provider_payload ?? [], $payload),
            ])->save();

            $order = $locked->order()->lockForUpdate()->first();
            if ($order && ! $order->isPaid()) {
                $order->forceFill([
                    'status' => $locked->status === PaymentStatus::Cancelled ? OrderStatus::Cancelled : OrderStatus::Failed,
                ])->save();

                if ($order->campaign) {
                    $this->tickets->release($order->campaign, (int) $order->quantity);
                }
            }

            $this->audit->log(AuditAction::PaymentFailed, $locked, ['status' => $previous->value], [
                'status' => $locked->status->value,
                'reference' => $locked->provider_reference,
            ]);
        });
    }

    /**
     * Réconciliation : interroge la passerelle et applique le statut réel.
     * Filet de sécurité si un webhook a été perdu.
     */
    public function reconcile(Payment $payment): Payment
    {
        if (! $payment->provider_payment_id) {
            return $payment;
        }

        $startedAt = microtime(true);
        $response = $this->futaye->getPayment($payment->provider_payment_id);
        $json = $response['json'] ?? [];

        $this->recordAttempt(
            $payment,
            'reconcile',
            $response['ok'] ? 'success' : 'failed',
            $response['status'],
            ['id' => $payment->provider_payment_id],
            $json,
            $response['ok'] ? null : mb_substr($response['body'], 0, 500),
            (int) round((microtime(true) - $startedAt) * 1000)
        );

        $status = strtoupper((string) ($json['status'] ?? ''));

        if ($status === 'SUCCESS') {
            $this->confirm($payment, $json);
        } elseif (in_array($status, ['FAILED', 'CANCELLED'], true)) {
            $this->fail($payment, $json);
        } elseif ($status === 'PENDING') {
            $payment->forceFill(['status' => PaymentStatus::Pending])->save();
        }

        return $payment->refresh();
    }

    // ------------------------------------------------------------------ helpers

    /** @param array<string, mixed> $payload */
    private function findPayment(array $payload): ?Payment
    {
        $candidates = array_filter([
            $payload['id'] ?? null,
            $payload['paymentId'] ?? null,
            $payload['payment_id'] ?? null,
        ]);

        foreach ($candidates as $candidate) {
            $payment = Payment::query()->where('provider_payment_id', $candidate)->first();
            if ($payment) {
                return $payment;
            }
        }

        $reference = $payload['reference'] ?? null;
        if ($reference) {
            return Payment::query()->where('provider_reference', $reference)->first();
        }

        return null;
    }

    /** @param array<string, mixed> $payload */
    private function amountMatches(Payment $payment, array $payload): bool
    {
        if (! isset($payload['amount'])) {
            return true; // certains événements (PAYOUT_SENT) ne portent pas de montant
        }

        $expected = (float) $payment->amount;
        $received = (float) $payload['amount'];

        if (abs($expected - $received) > 0.01) {
            return false;
        }

        if (isset($payload['currency']) && strtoupper((string) $payload['currency']) !== strtoupper($payment->currency)) {
            return false;
        }

        return true;
    }

    /** @param array<string, mixed> $payload */
    private function recordPayout(Payment $payment, array $payload): void
    {
        $payment->forceFill([
            'provider_payload' => array_merge($payment->provider_payload ?? [], ['payout' => $payload]),
        ])->save();
    }

    /** @param array<string, mixed> $json */
    private function extractPaymentId(array $json): ?string
    {
        foreach (['id', 'paymentId', 'payment_id', 'publicId', 'public_id'] as $key) {
            if (! empty($json[$key]) && is_string($json[$key])) {
                return $json[$key];
            }
        }

        return null;
    }

    /** @param array<string, mixed> $json */
    private function extractCheckoutUrl(array $json): ?string
    {
        foreach (['checkoutUrl', 'checkout_url', 'url', 'redirectUrl', 'redirect_url', 'paymentUrl'] as $key) {
            if (! empty($json[$key]) && is_string($json[$key])) {
                return $json[$key];
            }
        }

        // Repli : page de paiement hébergée construite depuis l'identifiant public.
        if ($id = $this->extractPaymentId($json)) {
            return rtrim((string) config('services.futaye.base_url'), '/').'/checkout/'.$id;
        }

        return null;
    }

    /** @param array<string, mixed> $options */
    private function resolveChannel(array $options): PaymentChannel
    {
        $channel = $options['channel'] ?? null;

        if ($channel instanceof PaymentChannel) {
            return $channel;
        }

        return match ($channel) {
            'card' => PaymentChannel::Card,
            'mobile_money', 'mobile-money' => PaymentChannel::MobileMoney,
            'rdv' => PaymentChannel::Rdv,
            default => ! empty($options['phone']) ? PaymentChannel::MobileMoney : PaymentChannel::Unknown,
        };
    }

    /**
     * @param  array<string, mixed>|null  $request
     * @param  array<string, mixed>|null  $response
     */
    private function recordAttempt(
        Payment $payment,
        string $action,
        string $status,
        ?int $httpStatus,
        ?array $request,
        ?array $response,
        ?string $error,
        int $durationMs,
    ): void {
        PaymentAttempt::query()->create([
            'payment_id' => $payment->id,
            'attempt_no' => (int) PaymentAttempt::query()->where('payment_id', $payment->id)->max('attempt_no') + 1,
            'action' => $action,
            'status' => $status,
            'http_status' => $httpStatus,
            'request_payload' => $request,
            'response_payload' => $response,
            'error' => $error,
            'duration_ms' => $durationMs,
        ]);
    }

    public function newIdempotencyKey(): string
    {
        return (string) Str::uuid();
    }
}
