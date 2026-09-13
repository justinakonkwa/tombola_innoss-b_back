<?php

namespace Database\Factories;

use App\Enums\CampaignStatus;
use App\Models\Campaign;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Campaign>
 */
class CampaignFactory extends Factory
{
    protected $model = Campaign::class;

    public function definition(): array
    {
        $name = 'Tombola '.fake()->unique()->word();

        return [
            'name' => $name,
            'slug' => Str::slug($name).'-'.fake()->unique()->numerify('####'),
            'description' => fake()->paragraph(),
            'short_description' => fake()->sentence(),
            'ticket_price' => '5.00',
            'currency' => 'USD',
            'max_tickets' => 10000,
            'tickets_sold' => 0,
            'tickets_reserved' => 0,
            'min_tickets_per_order' => 1,
            'max_tickets_per_order' => 100,
            'max_tickets_per_user' => 500,
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addDays(60),
            'draw_at' => now()->addDays(75),
            'status' => CampaignStatus::Active,
            'is_featured' => false,
        ];
    }

    public function scheduled(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => CampaignStatus::Scheduled,
            'starts_at' => now()->addDays(7),
            'ends_at' => now()->addDays(70),
            'draw_at' => now()->addDays(85),
        ]);
    }

    public function closed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => CampaignStatus::Closed,
            'ends_at' => now()->subHour(),
        ]);
    }

    public function withPrice(string $price): static
    {
        return $this->state(fn (array $attributes) => [
            'ticket_price' => $price,
        ]);
    }
}
