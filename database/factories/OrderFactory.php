<?php

namespace Database\Factories;

use App\Enums\OrderStatus;
use App\Models\Order;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\DB;

/**
 * @extends Factory<Order>
 */
class OrderFactory extends Factory
{
    protected $model = Order::class;

    public function definition(): array
    {
        return [
            'reference' => sprintf(
                'ORD-%d-%06d',
                (int) date('Y'),
                (int) DB::selectOne("SELECT nextval('orders_reference_seq') AS n")->n
            ),
            'user_id' => UserFactory::new(),
            'campaign_id' => CampaignFactory::new(),
            'quantity' => 2,
            'unit_price' => '5.00',
            // Le total est toujours recalculé à partir du prix unitaire et de la quantité.
            'total_amount' => fn (array $attributes) => number_format(
                (float) ($attributes['unit_price'] ?? 5) * (int) ($attributes['quantity'] ?? 2),
                2,
                '.',
                ''
            ),
            'currency' => 'USD',
            'status' => OrderStatus::Pending,
            'risk_score' => 0,
            'expires_at' => now()->addMinutes(30),
        ];
    }

    public function paid(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => OrderStatus::Paid,
            'paid_at' => now(),
        ]);
    }

    public function forUser(string $userId): static
    {
        return $this->state(fn (array $attributes) => [
            'user_id' => $userId,
        ]);
    }

    public function forCampaign(string $campaignId): static
    {
        return $this->state(fn (array $attributes) => [
            'campaign_id' => $campaignId,
        ]);
    }
}
