<?php

namespace Tests\Feature;

use App\Enums\RoleName;
use App\Models\Payment;
use App\Models\Refund;
use App\Models\Role;
use App\Models\User;
use Database\Factories\PaymentFactory;
use Database\Factories\UserFactory;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Remboursements : garde-fous financiers (cahier des charges §15, §26, §27).
 *
 * Un remboursement ne peut porter que sur un paiement encaissé, jamais au-delà
 * du montant payé, et — dès qu'un second approbateur existe — il ne peut pas
 * être approuvé par la personne qui l'a demandé (principe des quatre yeux).
 */
class RefundControlTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    private function staff(RoleName $role, string $email): User
    {
        /** @var User $user */
        $user = UserFactory::new()->admin()->create(['email' => $email]);
        $user->roles()->attach(Role::query()->where('name', $role->value)->value('id'));

        return $user;
    }

    private function bearer(User $user): string
    {
        return 'Bearer '.$user->createToken('test', ['mfa'])->plainTextToken;
    }

    /**
     * Les tests HTTP réutilisent la même instance d'application : le guard
     * d'authentification conserve l'utilisateur du premier appel. On le
     * réinitialise pour que chaque requête soit bien authentifiée par son
     * propre jeton.
     */
    private function asNewRequest(): void
    {
        $this->app['auth']->forgetGuards();
    }

    private function paidPayment(): Payment
    {
        /** @var Payment $payment */
        $payment = PaymentFactory::new()->success()->create(['amount' => '10.00']);

        return $payment;
    }

    public function test_a_refund_cannot_exceed_the_paid_amount(): void
    {
        $finance = $this->staff(RoleName::Finance, 'finance@tombola.test');
        $payment = $this->paidPayment();

        $this->withHeader('Authorization', $this->bearer($finance))
            ->postJson('/api/v1/admin/refunds', [
                'payment_id' => $payment->id,
                'amount' => 50,
                'reason' => 'Trop élevé',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('amount');
    }

    public function test_a_pending_payment_cannot_be_refunded(): void
    {
        $finance = $this->staff(RoleName::Finance, 'finance@tombola.test');
        $payment = PaymentFactory::new()->create(); // statut pending

        $this->withHeader('Authorization', $this->bearer($finance))
            ->postJson('/api/v1/admin/refunds', [
                'payment_id' => $payment->id,
                'amount' => 5,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('payment_id');
    }

    /** Un seul approbateur disponible : l'auto-approbation reste possible. */
    public function test_a_single_approver_may_approve_their_own_request(): void
    {
        $finance = $this->staff(RoleName::Finance, 'seul@tombola.test');
        $payment = $this->paidPayment();

        $refund = Refund::query()->create([
            'payment_id' => $payment->id,
            'order_id' => $payment->order_id,
            'amount' => 5,
            'currency' => 'USD',
            'status' => 'requested',
            'requested_by' => $finance->id,
        ]);

        $this->withHeader('Authorization', $this->bearer($finance))
            ->postJson('/api/v1/admin/refunds/'.$refund->id.'/approve')
            ->assertOk();
    }

    /** Dès qu'un second approbateur existe, l'auto-approbation est refusée. */
    public function test_a_second_approver_blocks_self_approval(): void
    {
        $finance = $this->staff(RoleName::Finance, 'demandeur@tombola.test');
        $this->staff(RoleName::Finance, 'autre@tombola.test');

        $payment = $this->paidPayment();

        $refund = Refund::query()->create([
            'payment_id' => $payment->id,
            'order_id' => $payment->order_id,
            'amount' => 5,
            'currency' => 'USD',
            'status' => 'requested',
            'requested_by' => $finance->id,
        ]);

        $this->withHeader('Authorization', $this->bearer($finance))
            ->postJson('/api/v1/admin/refunds/'.$refund->id.'/approve')
            ->assertStatus(422)
            ->assertJsonValidationErrors('refund');

        // Un autre approbateur peut, lui, traiter le dossier.
        $other = User::query()->where('email', 'autre@tombola.test')->firstOrFail();

        // Nouvelle requête : le guard d'authentification met en cache
        // l'utilisateur entre deux appels d'un même test.
        $this->asNewRequest();

        $this->withHeader('Authorization', $this->bearer($other))
            ->postJson('/api/v1/admin/refunds/'.$refund->id.'/approve')
            ->assertOk();

        $this->assertSame('completed', $refund->refresh()->status);
    }
}
