<?php

namespace Database\Factories;

use App\Enums\PaymentChannel;
use App\Enums\PaymentStatus;
use App\Models\Payment;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Payment>
 */
class PaymentFactory extends Factory
{
    protected $model = Payment::class;

    public function definition(): array
    {
        return [
            'order_id' => OrderFactory::new(),
            'provider' => 'futaye',
            'provider_payment_id' => 'PAY-'.Str::upper(Str::random(12)),
            'provider_reference' => 'FT-'.Str::upper(Str::random(10)),
            'channel' => PaymentChannel::MobileMoney,
            'operator' => 'airtel',
            'payer_phone' => '243'.fake()->numerify('#########'),
            'amount' => '10.00',
            'currency' => 'USD',
            'commission' => '0.00',
            'net' => '10.00',
            'status' => PaymentStatus::Pending,
        ];
    }

    public function success(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => PaymentStatus::Success,
            'confirmed_at' => now(),
        ]);
    }

    public function pending(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => PaymentStatus::Pending,
        ]);
    }

    public function failed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => PaymentStatus::Failed,
        ]);
    }

    public function forOrder(string $orderId): static
    {
        return $this->state(fn (array $attributes) => [
            'order_id' => $orderId,
        ]);
    }

    public function withAmount(string $amount): static
    {
        return $this->state(fn (array $attributes) => [
            'amount' => $amount,
            'net' => $amount,
        ]);
    }
}
