<?php

namespace Database\Factories;

use App\Enums\TicketStatus;
use App\Models\Ticket;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Les tickets d'un même lot doivent porter des numéros et des séries uniques :
 * on s'appuie sur la séquence PostgreSQL `tickets_serial_seq`, exactement comme
 * le fait TicketService::issueForPayment().
 *
 * @extends Factory<Ticket>
 */
class TicketFactory extends Factory
{
    protected $model = Ticket::class;

    public function definition(): array
    {
        return [
            'serial' => fn () => (int) DB::selectOne("SELECT nextval('tickets_serial_seq') AS n")->n,
            'ticket_number' => fn (array $attributes) => sprintf(
                'TMB-%d-%08d',
                (int) date('Y'),
                (int) $attributes['serial']
            ),
            'batch_id' => null,
            'user_id' => UserFactory::new(),
            'campaign_id' => CampaignFactory::new(),
            'order_id' => OrderFactory::new(),
            'payment_id' => PaymentFactory::new(),
            'status' => TicketStatus::Valid,
            'qr_token' => bin2hex(random_bytes(24)),
            'is_locked' => true,
            'issued_at' => now(),
        ];
    }

    public function valid(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => TicketStatus::Valid,
            'is_locked' => true,
        ]);
    }

    public function cancelled(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => TicketStatus::Cancelled,
            'is_locked' => false,
            'cancelled_at' => now(),
        ]);
    }

    public function forCampaign(string $campaignId): static
    {
        return $this->state(fn (array $attributes) => [
            'campaign_id' => $campaignId,
        ]);
    }

    public function forUser(string $userId): static
    {
        return $this->state(fn (array $attributes) => [
            'user_id' => $userId,
        ]);
    }
}
