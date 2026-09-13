<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Enums\PaymentChannel;
use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use Database\Factories\OrderFactory;
use Database\Factories\PaymentFactory;
use Database\Factories\UserFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Lecture des commandes et de leur paiement (tunnel d'achat).
 *
 * Ces tests couvrent une régression qui échappait à la suite : la relation
 * `Order::latestPayment()` utilisait `latestOfMany()`, donc un agrégat
 * `MAX(payments.id)`. Les clés étant des UUID, PostgreSQL répondait
 * « Undefined function: function max(uuid) does not exist » et les routes
 * `GET /me/orders` et `GET /orders/{reference}` renvoyaient une erreur 500 —
 * c'est-à-dire l'écran « Mes commandes » et tout le tunnel de paiement.
 */
class OrderPaymentResourceTest extends TestCase
{
    use RefreshDatabase;

    private function auth(User $user): array
    {
        return ['Authorization' => 'Bearer '.$user->createToken('test')->plainTextToken];
    }

    public function test_an_order_exposes_its_most_recent_payment(): void
    {
        /** @var Order $order */
        $order = OrderFactory::new()->create();

        $ancien = PaymentFactory::new()->create([
            'order_id' => $order->id,
            'status' => PaymentStatus::Failed,
            'created_at' => Carbon::now()->subHour(),
            'updated_at' => Carbon::now()->subHour(),
        ]);

        $recent = PaymentFactory::new()->create([
            'order_id' => $order->id,
            'status' => PaymentStatus::Success,
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]);

        $trouve = $order->fresh()->latestPayment;

        $this->assertNotNull($trouve, 'La relation ne doit pas être vide');
        $this->assertSame($recent->id, $trouve->id, 'Le paiement le plus récent doit être retenu');
        $this->assertNotSame($ancien->id, $trouve->id);
    }

    public function test_the_relation_works_when_eager_loaded(): void
    {
        /** @var Order $order */
        $order = OrderFactory::new()->create();
        PaymentFactory::new()->create(['order_id' => $order->id, 'created_at' => Carbon::now()->subHour()]);
        $recent = PaymentFactory::new()->create(['order_id' => $order->id, 'created_at' => Carbon::now()]);

        $charges = Order::query()->with('latestPayment')->whereKey($order->id)->get();

        $this->assertCount(1, $charges, 'Le chargement anticipé ne doit pas dupliquer la commande');
        $this->assertSame($recent->id, $charges->first()->latestPayment?->id);
    }

    public function test_the_order_detail_endpoint_returns_the_payment(): void
    {
        /** @var User $user */
        $user = UserFactory::new()->create();
        /** @var Order $order */
        $order = OrderFactory::new()->create(['user_id' => $user->id]);
        PaymentFactory::new()->create([
            'order_id' => $order->id,
            'status' => PaymentStatus::Pending,
            'channel' => PaymentChannel::Card,
        ]);

        $response = $this->withHeaders($this->auth($user))
            ->getJson('/api/v1/orders/'.$order->reference);

        $response->assertOk();
        $response->assertJsonPath('data.reference', $order->reference);
        $response->assertJsonPath('data.payment.status', 'pending');

        // Champs consommés par l'écran de paiement du frontend.
        $response->assertJsonStructure([
            'data' => [
                'reference', 'quantity', 'unit_price', 'total_amount', 'currency',
                'status', 'status_label',
                'payment' => ['id', 'status', 'status_label', 'channel', 'channel_label', 'amount', 'currency'],
            ],
        ]);
    }

    public function test_my_orders_endpoint_lists_orders_with_their_payment(): void
    {
        /** @var User $user */
        $user = UserFactory::new()->create();

        /** @var Order $avecPaiement */
        $avecPaiement = OrderFactory::new()->create(['user_id' => $user->id]);
        PaymentFactory::new()->success()->create(['order_id' => $avecPaiement->id]);

        /** @var Order $sansPaiement */
        $sansPaiement = OrderFactory::new()->create(['user_id' => $user->id]);

        $response = $this->withHeaders($this->auth($user))->getJson('/api/v1/me/orders');

        $response->assertOk();
        $this->assertCount(2, $response->json('data'));

        $parReference = collect($response->json('data'))->keyBy('reference');

        $this->assertSame('success', $parReference[$avecPaiement->reference]['payment']['status']);
        $this->assertNull($parReference[$sansPaiement->reference]['payment']);
    }

    public function test_a_participant_cannot_read_another_users_order(): void
    {
        /** @var User $proprietaire */
        $proprietaire = UserFactory::new()->create();
        /** @var User $curieux */
        $curieux = UserFactory::new()->create();

        /** @var Order $order */
        $order = OrderFactory::new()->create(['user_id' => $proprietaire->id]);
        PaymentFactory::new()->create(['order_id' => $order->id]);

        $this->withHeaders($this->auth($curieux))
            ->getJson('/api/v1/orders/'.$order->reference)
            ->assertNotFound();
    }

    public function test_an_order_paid_status_is_reflected_in_the_resource(): void
    {
        /** @var User $user */
        $user = UserFactory::new()->create();
        /** @var Order $order */
        $order = OrderFactory::new()->create([
            'user_id' => $user->id,
            'status' => OrderStatus::Paid,
            'paid_at' => now(),
        ]);

        PaymentFactory::new()->success()->create(['order_id' => $order->id]);

        $response = $this->withHeaders($this->auth($user))
            ->getJson('/api/v1/orders/'.$order->reference);

        $response->assertOk();
        $response->assertJsonPath('data.status', 'paid');
        $response->assertJsonPath('data.status_label', 'Payée');
        $response->assertJsonPath('data.payment.status_label', 'Payé');
    }
}
