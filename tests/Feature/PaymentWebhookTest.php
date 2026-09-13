<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\WebhookStatus;
use App\Models\Campaign;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentWebhook;
use App\Models\Ticket;
use App\Models\TicketBatch;
use App\Models\User;
use Database\Factories\UserFactory;
use Database\Factories\CampaignFactory;
use Database\Factories\OrderFactory;
use Database\Factories\PaymentFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Webhooks de la passerelle Futaye (cahier des charges §11, §38).
 *
 * Signature identique à celle du client réel :
 *   X-Futaye-Signature = HMAC-SHA256(token, timestamp + "." + rawBody)
 *   X-Futaye-Timestamp = epoch (tolérance ±5 min)
 */
class PaymentWebhookTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'futaye-test-token-secret';

    private const URI = '/api/v1/webhooks/futaye';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.futaye.token', self::TOKEN);
        config()->set('services.futaye.client_id', 'tombola-test-client');
        config()->set('services.futaye.base_url', 'https://futaye.test');
    }

    /**
     * @return array{user: User, campaign: Campaign, order: Order, payment: Payment}
     */
    private function pendingPayment(int $quantity = 3): array
    {
        /** @var User $user */
        $user = UserFactory::new()->create();

        /** @var Campaign $campaign */
        $campaign = CampaignFactory::new()->create([
            'ticket_price' => '5.00',
            'max_tickets' => 1000,
            'tickets_sold' => 0,
            'tickets_reserved' => 0,
        ]);

        /** @var Order $order */
        $order = OrderFactory::new()
            ->forUser($user->id)
            ->forCampaign($campaign->id)
            ->create([
                'quantity' => $quantity,
                'unit_price' => '5.00',
                'total_amount' => number_format($quantity * 5, 2, '.', ''),
                'status' => OrderStatus::Pending,
            ]);

        /** @var Payment $payment */
        $payment = PaymentFactory::new()
            ->forOrder($order->id)
            ->create([
                'amount' => $order->total_amount,
                'currency' => $order->currency,
                'status' => PaymentStatus::Pending,
                'provider_payment_id' => 'PAY-'.$order->reference,
                'provider_reference' => 'FT-'.$order->reference,
            ]);

        return compact('user', 'campaign', 'order', 'payment');
    }

    /** @param array<string, mixed> $payload */
    private function webhookCall(
        array $payload,
        ?string $signature = null,
        ?int $timestamp = null,
        ?string $token = null,
    ): TestResponse {
        $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $timestamp ??= time();
        $signature ??= hash_hmac('sha256', $timestamp.'.'.$body, $token ?? self::TOKEN);

        return $this->call('POST', self::URI, [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_FUTAYE_SIGNATURE' => $signature,
            'HTTP_X_FUTAYE_TIMESTAMP' => (string) $timestamp,
        ], (string) $body);
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function successPayload(Payment $payment, array $overrides = []): array
    {
        return array_merge([
            'event' => 'PAYMENT_SUCCESS',
            'id' => $payment->provider_payment_id,
            'reference' => $payment->provider_reference,
            'amount' => (float) $payment->amount,
            'currency' => $payment->currency,
            'commission' => 0.15,
            'net' => (float) $payment->amount - 0.15,
        ], $overrides);
    }

    public function test_webhook_with_an_invalid_signature_is_rejected_and_creates_no_tickets(): void
    {
        ['payment' => $payment, 'order' => $order] = $this->pendingPayment();

        $response = $this->webhookCall(
            $this->successPayload($payment),
            signature: str_repeat('f', 64),
        );

        $response->assertStatus(401);

        $this->assertSame(1, PaymentWebhook::query()->count());
        $this->assertDatabaseHas('payment_webhooks', [
            'status' => WebhookStatus::Rejected->value,
        ]);

        $this->assertSame(0, Ticket::query()->count());
        $this->assertSame(0, TicketBatch::query()->count());
        $this->assertSame(PaymentStatus::Pending, $payment->fresh()->status);
        $this->assertSame(OrderStatus::Pending, $order->fresh()->status);
        $this->assertDatabaseHas('security_events', ['type' => 'webhook_signature_invalid']);
    }

    public function test_webhook_with_a_stale_timestamp_is_rejected(): void
    {
        ['payment' => $payment] = $this->pendingPayment();

        $staleTimestamp = time() - 3600; // au-delà de la tolérance de 5 minutes

        $response = $this->webhookCall($this->successPayload($payment), timestamp: $staleTimestamp);

        $response->assertStatus(401);
        $this->assertSame(0, Ticket::query()->count());
        $this->assertSame(PaymentStatus::Pending, $payment->fresh()->status);
    }

    public function test_a_valid_payment_success_webhook_confirms_the_payment_marks_the_order_paid_and_issues_tickets(): void
    {
        ['payment' => $payment, 'order' => $order, 'campaign' => $campaign] = $this->pendingPayment(3);

        $response = $this->webhookCall($this->successPayload($payment));

        $response->assertOk();

        $this->assertSame(PaymentStatus::Success, $payment->fresh()->status);
        $this->assertNotNull($payment->fresh()->confirmed_at);
        $this->assertSame(OrderStatus::Paid, $order->fresh()->status);
        $this->assertNotNull($order->fresh()->paid_at);

        // 3 tickets émis, un seul lot, rattachés au paiement et à la commande.
        $this->assertSame(3, Ticket::query()->where('payment_id', $payment->id)->count());
        $this->assertSame(1, TicketBatch::query()->where('payment_id', $payment->id)->count());

        $batch = TicketBatch::query()->where('payment_id', $payment->id)->firstOrFail();
        $this->assertSame(3, (int) $batch->quantity);
        $this->assertSame($campaign->id, $batch->campaign_id);

        $this->assertDatabaseHas('payment_webhooks', [
            'event' => 'PAYMENT_SUCCESS',
            'status' => WebhookStatus::Processed->value,
        ]);
    }

    public function test_replaying_the_same_webhook_body_is_idempotent(): void
    {
        ['payment' => $payment, 'order' => $order] = $this->pendingPayment(3);

        $payload = $this->successPayload($payment);

        $this->webhookCall($payload)->assertOk();

        $ticketsAfterFirstCall = Ticket::query()->count();
        $batchesAfterFirstCall = TicketBatch::query()->count();

        // Rejeu strictement identique (nouvel horodatage, même corps signé).
        $replay = $this->webhookCall($payload);

        $replay->assertOk();
        $this->assertSame('duplicate', $replay->json('status'));

        $this->assertSame(WebhookStatus::Duplicate, PaymentWebhook::query()->latest('id')->first()->status);
        $this->assertSame($ticketsAfterFirstCall, Ticket::query()->count());
        $this->assertSame($batchesAfterFirstCall, TicketBatch::query()->count());
        $this->assertSame(3, Ticket::query()->where('payment_id', $payment->id)->count());
        $this->assertSame(1, TicketBatch::query()->where('payment_id', $payment->id)->count());

        // Un seul enregistrement de webhook, une seule confirmation.
        $this->assertSame(1, PaymentWebhook::query()->count());
        $this->assertSame(1, PaymentWebhook::query()->where('status', WebhookStatus::Duplicate->value)->count());
        $this->assertNotNull($payment->fresh()->confirmed_at);
        $this->assertSame(OrderStatus::Paid, $order->fresh()->status);
    }

    public function test_a_webhook_whose_amount_differs_from_the_order_total_is_rejected_and_flagged(): void
    {
        ['payment' => $payment, 'order' => $order] = $this->pendingPayment(3);

        // Montant falsifié : la commande vaut 15.00 USD, le webhook annonce 1.00 USD.
        $payload = $this->successPayload($payment, ['amount' => 1.00]);

        $response = $this->webhookCall($payload);

        $this->assertGreaterThanOrEqual(400, $response->status());

        // Le webhook est journalisé comme rejeté…
        $this->assertDatabaseHas('payment_webhooks', [
            'event' => 'PAYMENT_SUCCESS',
            'status' => WebhookStatus::Rejected->value,
        ]);

        // …et l'anomalie est remontée comme événement de sécurité critique.
        $this->assertDatabaseHas('security_events', [
            'type' => 'webhook_amount_mismatch',
            'severity' => 'critical',
        ]);

        // Aucun ticket, aucun paiement confirmé, aucune commande payée.
        $this->assertSame(0, Ticket::query()->count());
        $this->assertSame(0, TicketBatch::query()->count());
        $this->assertSame(PaymentStatus::Pending, $payment->fresh()->status);
        $this->assertSame(OrderStatus::Pending, $order->fresh()->status);
    }

    public function test_a_webhook_signed_with_the_wrong_token_is_rejected(): void
    {
        ['payment' => $payment] = $this->pendingPayment();

        $response = $this->webhookCall(
            $this->successPayload($payment),
            token: 'a-different-token',
        );

        $response->assertStatus(401);
        $this->assertSame(0, Ticket::query()->count());
        $this->assertSame(PaymentStatus::Pending, $payment->fresh()->status);
    }
}
