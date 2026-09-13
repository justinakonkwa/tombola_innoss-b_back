<?php

namespace Tests\Feature;

use App\Enums\PaymentStatus;
use App\Enums\TicketStatus;
use App\Exceptions\TicketIssuanceException;
use App\Models\Campaign;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Ticket;
use App\Models\TicketBatch;
use App\Models\User;
use App\Services\TicketService;
use Database\Factories\UserFactory;
use Database\Factories\CampaignFactory;
use Database\Factories\OrderFactory;
use Database\Factories\PaymentFactory;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Émission des tickets (cahier des charges §7, §9, §10, §38).
 *
 * Ces tests couvrent les invariants les plus critiques de la plateforme :
 * aucun ticket sans paiement confirmé, un paiement = un seul lot (idempotence),
 * et l'unicité bloquée en base même en cas de contournement applicatif.
 */
class TicketIssuanceTest extends TestCase
{
    use RefreshDatabase;

    private TicketService $tickets;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tickets = app(TicketService::class);
    }

    /**
     * @return array{order: Order, payment: Payment, campaign: Campaign, user: User}
     */
    private function orderWithPayment(PaymentStatus $status, int $quantity = 3): array
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
            ]);

        /** @var Payment $payment */
        $payment = PaymentFactory::new()
            ->forOrder($order->id)
            ->create([
                'amount' => $order->total_amount,
                'status' => $status,
            ]);

        return compact('order', 'payment', 'campaign', 'user');
    }

    public function test_a_payment_that_is_not_success_cannot_produce_tickets(): void
    {
        ['payment' => $payment] = $this->orderWithPayment(PaymentStatus::Pending);
        ['payment' => $failed] = $this->orderWithPayment(PaymentStatus::Failed);
        ['payment' => $cancelled] = $this->orderWithPayment(PaymentStatus::Cancelled);
        ['payment' => $created] = $this->orderWithPayment(PaymentStatus::Created);

        foreach ([$payment, $failed, $cancelled, $created] as $unsettled) {
            try {
                $this->tickets->issueForPayment($unsettled);
                $this->fail('Un paiement non confirmé ne doit jamais produire de tickets.');
            } catch (TicketIssuanceException $e) {
                $this->assertStringContainsString('confirmé', $e->getMessage());
            }
        }

        $this->assertSame(0, Ticket::query()->count());
        $this->assertSame(0, TicketBatch::query()->count());
    }

    public function test_a_confirmed_payment_produces_exactly_quantity_locked_valid_tickets(): void
    {
        ['payment' => $payment, 'campaign' => $campaign, 'order' => $order, 'user' => $user] =
            $this->orderWithPayment(PaymentStatus::Success, 3);

        $batch = $this->tickets->issueForPayment($payment);

        $this->assertSame($payment->id, $batch->payment_id);
        $this->assertSame(3, (int) $batch->quantity);
        $this->assertSame($order->id, $batch->order_id);
        $this->assertSame($campaign->id, $batch->campaign_id);
        $this->assertSame($user->id, $batch->user_id);

        $tickets = Ticket::query()->where('batch_id', $batch->id)->orderBy('serial')->get();

        $this->assertCount(3, $tickets);

        foreach ($tickets as $ticket) {
            // Format imposé : TMB-<année>-<8 chiffres>.
            $this->assertMatchesRegularExpression('/^TMB-\d{4}-\d{8}$/', $ticket->ticket_number);
            $this->assertSame(TicketStatus::Valid, $ticket->status);
            $this->assertTrue($ticket->is_locked);
            $this->assertNotNull($ticket->issued_at);
            $this->assertNotEmpty($ticket->qr_token);
            $this->assertSame($campaign->id, $ticket->campaign_id);
            $this->assertSame($payment->id, $ticket->payment_id);
        }

        // Numéros et séries strictement uniques.
        $this->assertCount(3, $tickets->pluck('ticket_number')->unique());
        $this->assertCount(3, $tickets->pluck('serial')->unique());

        // Le lot est journalisé dans la campagne : réservation transformée en vente.
        $campaign->refresh();
        $this->assertSame(3, (int) $campaign->tickets_sold);
        $this->assertSame(0, (int) $campaign->tickets_reserved);

        // Le lot conserve la liste des numéros générés (preuve d'intégrité).
        $this->assertCount(3, $batch->numbers ?? []);
    }

    public function test_calling_issue_for_payment_twice_returns_the_same_batch_and_creates_no_extra_tickets(): void
    {
        ['payment' => $payment] = $this->orderWithPayment(PaymentStatus::Success, 4);

        $first = $this->tickets->issueForPayment($payment);
        $ticketsAfterFirst = Ticket::query()->count();

        // Rejeu (double webhook, retry de réconciliation…).
        $second = $this->tickets->issueForPayment($payment->fresh());

        $this->assertSame($first->id, $second->id);
        $this->assertSame($ticketsAfterFirst, Ticket::query()->count());
        $this->assertSame(4, Ticket::query()->where('payment_id', $payment->id)->count());
        $this->assertSame(1, TicketBatch::query()->where('payment_id', $payment->id)->count());
    }

    public function test_a_second_ticket_batch_for_the_same_payment_is_rejected_by_the_database(): void
    {
        ['payment' => $payment, 'campaign' => $campaign, 'order' => $order, 'user' => $user] =
            $this->orderWithPayment(PaymentStatus::Success, 2);

        $this->tickets->issueForPayment($payment);

        // Contournement applicatif volontaire : insertion brute d'un second lot.
        // L'index UNIQUE ticket_batches.payment_id doit le refuser.
        $this->expectException(QueryException::class);

        DB::table('ticket_batches')->insert([
            'id' => (string) Str::uuid(),
            'order_id' => $order->id,
            'payment_id' => $payment->id,
            'campaign_id' => $campaign->id,
            'user_id' => $user->id,
            'quantity' => 2,
            'first_serial' => 1,
            'last_serial' => 2,
            'checksum' => str_repeat('a', 64),
            'generated_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
