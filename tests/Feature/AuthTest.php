<?php

namespace Tests\Feature;

use App\Enums\RoleName;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Database\Factories\UserFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Authentification (cahier des charges §5, §12).
 */
class AuthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Le rôle participant doit exister pour être attribué à l'inscription.
        $this->seed(RolePermissionSeeder::class);
    }

    /** @return array<string, mixed> */
    private function registrationPayload(array $overrides = []): array
    {
        return array_merge([
            'first_name' => 'Jean',
            'last_name' => 'Mbala',
            'phone' => '243810000001',
            'email' => 'jean.mbala@example.com',
            'country' => 'CD',
            'password' => 'MotDePasse++2026',
            'password_confirmation' => 'MotDePasse++2026',
            'accept_terms' => true,
        ], $overrides);
    }

    public function test_registration_creates_user_with_argon2id_password_and_participant_role(): void
    {
        $response = $this->postJson('/api/v1/auth/register', $this->registrationPayload());

        $response->assertCreated();

        /** @var User $user */
        $user = User::query()->where('email', 'jean.mbala@example.com')->firstOrFail();

        // Mot de passe haché (jamais stocké en clair) avec l'algorithme Argon2id.
        $this->assertNotSame('MotDePasse++2026', $user->password);
        $this->assertTrue(Hash::check('MotDePasse++2026', $user->password));
        $this->assertSame('argon2id', password_get_info($user->password)['algoName']);

        // Le rôle participant est attribué automatiquement.
        $this->assertTrue($user->hasRole(RoleName::Participant));
        $this->assertTrue($user->hasRole('participant'));
        $this->assertFalse($user->is_admin);
        $this->assertFalse($user->isStaff());

        // Le hash n'est jamais sérialisé dans la réponse.
        $response->assertJsonMissing(['password' => $user->password]);
        $response->assertJsonPath('data.user.first_name', 'Jean');

        // Le visiteur n'est pas encore authentifié : l'e-mail n'est pas exposé
        // (minimisation des données, cf. UserResource).
        $this->assertNull($response->json('data.user.email'));

        // Le rôle participant est renvoyé dans la réponse d'inscription.
        $this->assertContains('participant', (array) $response->json('data.user.roles'));

        // Une clé d'idempotence métier : un seul utilisateur créé.
        $this->assertDatabaseCount('users', 1);
    }

    public function test_login_with_wrong_password_fails_with_generic_message(): void
    {
        /** @var User $user */
        $user = UserFactory::new()->create([
            'email' => 'sarah@example.com',
            'password' => 'Correct++2026',
        ]);

        $response = $this->postJson('/api/v1/auth/login', [
            'identifier' => 'sarah@example.com',
            'password' => 'MauvaisMotDePasse++',
        ]);

        $response->assertStatus(401);
        // Message générique : l'existence du compte n'est pas révélée.
        $response->assertJsonPath('message', 'Identifiants invalides.');
        $response->assertJsonMissing(['access_token']);

        $this->assertSame(1, (int) $user->fresh()->failed_login_attempts);
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_account_locks_after_five_failed_attempts(): void
    {
        /** @var User $user */
        $user = UserFactory::new()->create([
            'email' => 'lock@example.com',
            'password' => 'Correct++2026',
        ]);

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->postJson('/api/v1/auth/login', [
                'identifier' => 'lock@example.com',
                'password' => 'MauvaisMotDePasse++',
            ])->assertStatus(401);
        }

        $user->refresh();

        $this->assertNotNull($user->locked_until);
        $this->assertTrue($user->isLocked());
        $this->assertSame(0, (int) $user->failed_login_attempts);

        // Même avec le bon mot de passe, le compte reste verrouillé.
        $response = $this->postJson('/api/v1/auth/login', [
            'identifier' => 'lock@example.com',
            'password' => 'Correct++2026',
        ]);

        $response->assertStatus(401);
        $this->assertStringContainsString('verrouillé', (string) $response->json('message'));
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_login_succeeds_and_returns_access_and_refresh_tokens(): void
    {
        /** @var User $user */
        $user = UserFactory::new()->create([
            'email' => 'ok@example.com',
            'phone' => '243810000099',
            'password' => 'Correct++2026',
        ]);

        $response = $this->postJson('/api/v1/auth/login', [
            'identifier' => 'ok@example.com',
            'password' => 'Correct++2026',
        ]);

        $response->assertOk();
        $response->assertJsonStructure([
            'data' => [
                // Le visiteur n'est pas authentifié : les champs sensibles (email,
                // téléphone) sont absents de la réponse de connexion.
                'user' => ['id', 'first_name', 'last_name', 'roles'],
                'tokens' => ['access_token', 'token_type', 'expires_at', 'refresh_token', 'refresh_expires_at'],
            ],
        ]);

        $accessToken = (string) $response->json('data.tokens.access_token');
        $refreshToken = (string) $response->json('data.tokens.refresh_token');

        $this->assertNotSame('', $accessToken);
        $this->assertSame('Bearer', $response->json('data.tokens.token_type'));
        $this->assertSame(64, strlen($refreshToken));

        // Le refresh token est stocké haché (jamais en clair).
        $this->assertDatabaseHas('user_sessions', [
            'user_id' => $user->id,
            'refresh_token_hash' => hash('sha256', $refreshToken),
        ]);
        $this->assertDatabaseMissing('user_sessions', ['refresh_token_hash' => $refreshToken]);

        $this->assertSame(0, (int) $user->fresh()->failed_login_attempts);
        $this->assertNotNull($user->fresh()->last_login_at);

        // Le jeton d'accès ouvre bien l'espace personnel.
        $this->withHeader('Authorization', 'Bearer '.$accessToken)
            ->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonPath('data.email', 'ok@example.com');
    }
}
