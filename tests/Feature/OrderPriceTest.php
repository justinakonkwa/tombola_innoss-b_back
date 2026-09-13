<?php

namespace Tests\Feature;

use App\Enums\CampaignStatus;
use App\Models\Campaign;
use App\Models\Order;
use App\Models\Ticket;
use App\Models\User;
use Database\Factories\UserFactory;
use Database\Factories\CampaignFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Le prix est une décision serveur (cahier des charges §8, §14, §38).
 *
 * Aucun montant fourni par le client ne doit influencer la commande : le prix
 * unitaire provient de la campagne et le total est recalculé côté serveur.
 */
class OrderPriceTest extends TestCase
{
    use RefreshDatabase;

    private function campaign(): Campaign
    {
        /** @var Campaign $campaign */
        $campaign = CampaignFactory::new()->create([
            'ticket_price' => '5.00',
            'currency' => 'USD',
            'min_tickets_per_order' => 1,
            'max_tickets_per_order' => 100,
            'max_tickets_per_user' => 500,
            'max_tickets' => 1000,
            'tickets_sold' => 0,
            'tickets_reserved' => 0,
            'status' => CampaignStatus::Active,
        ]);

        return $campaign;
    }

    public function test_the_server_computes_the_total_and_ignores_any_amount_sent_by_the_client(): void
    {
        /** @var User $user */
        $user = UserFactory::new()->create();
        $campaign = $this->campaign();

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/v1/orders', [
            'campaign' => $campaign->slug,
            'quantity' => 3,
            // Champs de prix forgés par le client : ils doivent être ignorés.
            'unit_price' => 0.01,
            'total_amount' => 0.01,
            'amount' => 0.01,
            'price' => 0.01,
            'currency' => 'CDF',
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.quantity', 3);

        $this->assertEquals(5.0, $response->json('data.unit_price'));
        $this->assertEquals(15.0, $response->json('data.total_amount'));

        /** @var Order $order */
        $order = Order::query()->sole();

        $this->assertSame($user->id, $order->user_id);
        $this->assertSame($campaign->id, $order->campaign_id);
        $this->assertSame(3, (int) $order->quantity);
        $this->assertSame('5.00', $order->unit_price);
        $this->assertSame('15.00', $order->total_amount);
        $this->assertSame('USD', $order->currency);

        // Aucun ticket avant confirmation du paiement : seule une réservation est posée.
        $this->assertSame(0, Ticket::query()->count());
        $this->assertSame(3, (int) $campaign->fresh()->tickets_reserved);
    }

    public function test_a_ticket_price_sent_in_the_request_body_is_ignored(): void
    {
        /** @var User $user */
        $user = UserFactory::new()->create();
        $campaign = $this->campaign();

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/v1/orders', [
            'campaign' => $campaign->slug,
            'quantity' => 1,
            'ticket_price' => 0.01,
            'unit_price' => 0.01,
            'total_amount' => 0.01,
        ]);

        $response->assertCreated();

        /** @var Order $order */
        $order = Order::query()->sole();

        $this->assertSame('5.00', $order->unit_price);
        $this->assertSame('5.00', $order->total_amount);
        $this->assertEquals(5.0, $response->json('data.total_amount'));
    }

    public function test_exceeding_the_maximum_tickets_per_order_is_refused(): void
    {
        /** @var User $user */
        $user = UserFactory::new()->create();
        $campaign = $this->campaign();

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/v1/orders', [
            'campaign' => $campaign->slug,
            'quantity' => 101, // max_tickets_per_order = 100
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('code', 'OrderException');

        // Aucune commande, aucune réservation : le refus est total.
        $this->assertDatabaseCount('orders', 0);
        $this->assertSame(0, (int) $campaign->fresh()->tickets_reserved);
    }

    public function test_below_the_minimum_tickets_per_order_is_refused(): void
    {
        /** @var User $user */
        $user = UserFactory::new()->create();

        /** @var Campaign $campaign */
        $campaign = CampaignFactory::new()->create([
            'ticket_price' => '5.00',
            'min_tickets_per_order' => 2,
            'max_tickets_per_order' => 100,
            'status' => CampaignStatus::Active,
        ]);

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/v1/orders', [
            'campaign' => $campaign->slug,
            'quantity' => 1, // min_tickets_per_order = 2
        ]);

        $response->assertStatus(422);
        $this->assertDatabaseCount('orders', 0);
    }
}
