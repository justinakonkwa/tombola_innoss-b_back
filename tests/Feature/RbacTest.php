<?php

namespace Tests\Feature;

use App\Enums\RoleName;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Database\Factories\UserFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Contrôle d'accès du back-office (cahier des charges §15, §16).
 *
 * Trois barrières cumulées : `auth:sanctum` → `staff` → `mfa`, puis la
 * permission fine portée par la route.
 */
class RbacTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    private function assignRole(User $user, RoleName $role): void
    {
        $roleId = Role::query()->where('name', $role->value)->value('id');

        $user->roles()->attach($roleId);
    }

    private function bearer(User $user, array $abilities = ['*']): string
    {
        return 'Bearer '.$user->createToken('test', $abilities)->plainTextToken;
    }

    public function test_a_participant_gets_403_on_the_admin_dashboard(): void
    {
        /** @var User $user */
        $user = UserFactory::new()->create();
        $this->assignRole($user, RoleName::Participant);

        $response = $this->withHeader('Authorization', $this->bearer($user))
            ->getJson('/api/v1/admin/dashboard');

        // Le middleware `staff` coupe avant même le MFA et le contrôleur.
        $response->assertForbidden();
        $response->assertJsonMissing(['data']);
    }

    public function test_a_staff_user_without_an_mfa_token_is_refused_with_mfa_required(): void
    {
        /** @var User $user */
        $user = UserFactory::new()->admin()->create();
        $this->assignRole($user, RoleName::Admin);

        // Jeton non-MFA : première connexion avant vérification TOTP.
        $response = $this->withHeader('Authorization', $this->bearer($user, ['*']))
            ->getJson('/api/v1/admin/dashboard');

        $response->assertForbidden();
        $response->assertJsonPath('code', 'mfa_required');
    }

    public function test_a_super_admin_with_an_mfa_enabled_token_gets_200(): void
    {
        /** @var User $user */
        $user = UserFactory::new()->admin()->create();
        $this->assignRole($user, RoleName::SuperAdmin);

        $response = $this->withHeader('Authorization', $this->bearer($user, ['mfa']))
            ->getJson('/api/v1/admin/dashboard');

        $response->assertOk();
        $response->assertJsonStructure([
            'data' => [
                'totals' => ['participants', 'tickets_sold', 'revenue', 'transactions'],
                'conversion_rate',
                'sales_by_day',
                'per_campaign',
            ],
        ]);
    }

    public function test_an_unauthenticated_request_is_rejected_with_401(): void
    {
        $this->getJson('/api/v1/admin/dashboard')->assertUnauthorized();
    }
}
