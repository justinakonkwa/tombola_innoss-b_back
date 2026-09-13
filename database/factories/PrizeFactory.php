<?php

namespace Database\Factories;

use App\Enums\PrizeStatus;
use App\Models\Prize;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Prize>
 */
class PrizeFactory extends Factory
{
    protected $model = Prize::class;

    public function definition(): array
    {
        $name = 'Lot '.fake()->unique()->word();

        return [
            'campaign_id' => CampaignFactory::new(),
            'name' => $name,
            'slug' => Str::slug($name).'-'.fake()->unique()->numerify('###'),
            'description' => fake()->sentence(),
            'media' => [
                ['type' => 'image', 'url' => '/media/lot-'.fake()->numerify('###').'.jpg', 'alt' => $name],
            ],
            'indicative_value' => '1000.00',
            'currency' => 'USD',
            'quantity' => 1,
            'quantity_awarded' => 0,
            'is_main' => false,
            'position' => 0,
            'status' => PrizeStatus::Published,
        ];
    }

    public function main(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_main' => true,
            'position' => 0,
        ]);
    }

    public function withQuantity(int $quantity): static
    {
        return $this->state(fn (array $attributes) => [
            'quantity' => $quantity,
        ]);
    }

    public function forCampaign(string $campaignId): static
    {
        return $this->state(fn (array $attributes) => [
            'campaign_id' => $campaignId,
        ]);
    }
}
