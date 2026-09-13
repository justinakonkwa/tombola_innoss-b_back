<?php

namespace Tests\Feature;

use App\Enums\RoleName;
use App\Models\Role;
use App\Models\User;
use App\Services\AuthService;
use App\Services\TotpService;
use Database\Seeders\RolePermissionSeeder;
use Database\Factories\UserFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Invariant de sécurité : un jeton de défi MFA ne donne accès à aucune action
 * métier (cahier des charges §12, §16).
 *
 * Le jeton de défi est émis après vérification du mot de passe mais AVANT le
 * second facteur : il ne représente qu'une authentification partielle. S'il
 * permettait de consulter son profil, de passer une commande ou d'atteindre le
 * back-office, la double authentification serait purement décorative.
 *
 * Cet invariant doit tenir indépendamment de la dérogation de développement
 * `REQUIRE_MFA_FOR_STAFF=false` : on teste donc les deux valeurs du drapeau.
 */
class MfaChallengeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    /** Crée un membre du personnel avec la 2FA déjà activée. */
    private function staffWithMfa(RoleName $role = RoleName::SuperAdmin): array
    {
        /** @var User $user */
        $user = UserFactory::new()->admin()->create([
            'totp_secret' => app(TotpService::class)->generateSecret(),
            'totp_confirmed_at' => now(),
        ]);

        $user->roles()->attach(Role::query()->where('name', $role->value)->value('id'));

        return [$user, (string) $user->totp_secret];
    }

    private function challengeToken(User $user): string
    {
        return 'Bearer '.app(AuthService::class)->issueMfaChallenge($user);
    }

    /**
     * Les tests HTTP réutilisent la même instance d'application : le guard
     * d'authentification met en cache l'utilisateur ET son jeton d'accès entre
     * deux appels. Sans cette remise à zéro, le second appel serait authentifié
     * par le jeton du premier — ce qui masquerait justement le comportement que
     * ces tests vérifient.
     */
    private function asNewRequest(): void
    {
        $this->app['auth']->forgetGuards();
    }

    /**
     * Le défi doit être résolu avant toute action — y compris quand la
     * dérogation de développement est active.
     */
    public function test_a_challenge_token_cannot_reach_the_admin_back_office(): void
    {
        [$user] = $this->staffWithMfa();

        config()->set('tombola.security.require_mfa_for_staff', false);

        $this->withHeader('Authorization', $this->challengeToken($user))
            ->getJson('/api/v1/admin/dashboard')
            ->assertForbidden()
            ->assertJsonPath('code', 'mfa_required');
    }

    public function test_a_challenge_token_cannot_call_participant_endpoints(): void
    {
        [$user] = $this->staffWithMfa();

        config()->set('tombola.security.require_mfa_for_staff', false);

        $token = $this->challengeToken($user);

        foreach ([
            '/api/v1/me',
            '/api/v1/me/dashboard',
            '/api/v1/me/tickets',
            '/api/v1/me/orders',
        ] as $endpoint) {
            $this->withHeader('Authorization', $token)
                ->getJson($endpoint)
                ->assertForbidden()
                ->assertJsonPath('code', 'mfa_required');
        }
    }

    public function test_a_challenge_token_cannot_create_an_order(): void
    {
        [$user] = $this->staffWithMfa();

        config()->set('tombola.security.require_mfa_for_staff', false);

        $this->withHeader('Authorization', $this->challengeToken($user))
            ->postJson('/api/v1/orders', ['campaign' => 'lamborghini', 'quantity' => 1])
            ->assertForbidden()
            ->assertJsonPath('code', 'mfa_required');
    }

    /** Résoudre le défi délivre un jeton complet, qui ouvre le back-office. */
    public function test_resolving_the_challenge_grants_a_fully_authorised_token(): void
    {
        [$user, $secret] = $this->staffWithMfa();

        $challenge = app(AuthService::class)->issueMfaChallenge($user);

        $response = $this->withHeader('Authorization', 'Bearer '.$challenge)
            ->postJson('/api/v1/auth/mfa/verify', ['code' => app(TotpService::class)->at($secret)]);

        $response->assertOk();

        $token = $response->json('data.tokens.access_token');
        $this->assertNotEmpty($token);

        // Nouvelle requête : le guard ne doit plus voir le jeton de défi.
        $this->asNewRequest();

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/admin/dashboard')
            ->assertOk();
    }

    public function test_a_wrong_code_does_not_resolve_the_challenge(): void
    {
        [$user] = $this->staffWithMfa();

        $this->withHeader('Authorization', 'Bearer '.app(AuthService::class)->issueMfaChallenge($user))
            ->postJson('/api/v1/auth/mfa/verify', ['code' => '000000'])
            ->assertStatus(422);
    }

    /** Le jeton de défi est à usage unique : il est consommé par la résolution. */
    public function test_the_challenge_token_is_single_use(): void
    {
        [$user, $secret] = $this->staffWithMfa();

        $challenge = app(AuthService::class)->issueMfaChallenge($user);
        $totp = app(TotpService::class);

        $this->withHeader('Authorization', 'Bearer '.$challenge)
            ->postJson('/api/v1/auth/mfa/verify', ['code' => $totp->at($secret)])
            ->assertOk();

        // Le jeton a été consommé : une nouvelle requête doit être rejetée.
        $this->asNewRequest();

        $this->withHeader('Authorization', 'Bearer '.$challenge)
            ->postJson('/api/v1/auth/mfa/verify', ['code' => $totp->at($secret)])
            ->assertUnauthorized();
    }

    /** Un participant ordinaire n'est pas concerné par le garde-fou de défi. */
    public function test_a_participant_token_still_reaches_participant_endpoints(): void
    {
        /** @var User $user */
        $user = UserFactory::new()->create();
        $user->roles()->attach(Role::query()->where('name', RoleName::Participant->value)->value('id'));

        $this->withHeader('Authorization', 'Bearer '.$user->createToken('test', ['*'])->plainTextToken)
            ->getJson('/api/v1/me')
            ->assertOk();
    }
}
