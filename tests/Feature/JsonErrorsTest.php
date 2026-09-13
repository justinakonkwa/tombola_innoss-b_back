<?php

namespace Tests\Feature;

use App\Enums\RoleName;
use App\Models\Role;
use App\Models\User;
use Database\Factories\UserFactory;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Toute réponse de l'API est du JSON, y compris les erreurs et y compris
 * lorsqu'un client n'envoie aucun en-tête `Accept` (cahier des charges §14).
 *
 * Sans ce durcissement, une requête non authentifiée déclenchait
 * `route('login')` — route inexistante dans une API — et la réponse devenait
 * une page HTML 500 au lieu d'un 401 exploitable par un client.
 */
class JsonErrorsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    public function test_an_unauthenticated_api_request_without_accept_header_returns_json_401(): void
    {
        // `call()` n'ajoute aucun en-tête Accept, contrairement à `getJson()`.
        $response = $this->call('GET', '/api/v1/me');

        $response->assertStatus(401);
        $this->assertStringContainsString('application/json', (string) $response->headers->get('Content-Type'));
        $this->assertSame(['message' => 'Authentification requise.'], $response->json());
    }

    public function test_a_protected_admin_route_without_accept_header_returns_json(): void
    {
        $response = $this->call('GET', '/api/v1/admin/dashboard');

        $response->assertStatus(401);
        $this->assertStringContainsString('application/json', (string) $response->headers->get('Content-Type'));
        $this->assertIsArray($response->json());
    }

    public function test_a_missing_resource_is_rendered_as_json_404(): void
    {
        /** @var User $user */
        $user = UserFactory::new()->create();
        $user->roles()->attach(Role::query()->where('name', RoleName::Participant->value)->value('id'));

        $response = $this->call(
            'POST',
            '/api/v1/orders',
            [],
            [],
            [],
            [
                'HTTP_AUTHORIZATION' => 'Bearer '.$user->createToken('test')->plainTextToken,
                'CONTENT_TYPE' => 'application/json',
            ],
            json_encode(['campaign' => 'campagne-inexistante', 'quantity' => 1])
        );

        // Campagne inexistante → 404 JSON, jamais une page HTML.
        $response->assertStatus(404);
        $this->assertStringContainsString('application/json', (string) $response->headers->get('Content-Type'));
    }

    public function test_an_unknown_api_route_returns_json_404(): void
    {
        $response = $this->call('GET', '/api/v1/route-inexistante');

        $response->assertStatus(404);
        $this->assertStringContainsString('application/json', (string) $response->headers->get('Content-Type'));
        $this->assertSame('Ressource introuvable.', $response->json('message'));
    }

    public function test_a_throttled_endpoint_returns_json_429(): void
    {
        // 11 tentatives sur une limite de 10/minute déclenche la limitation.
        for ($attempt = 0; $attempt < 11; $attempt++) {
            $response = $this->call(
                'POST',
                '/api/v1/auth/login',
                [],
                [],
                [],
                ['CONTENT_TYPE' => 'application/json'],
                json_encode(['identifier' => 'inconnu@example.com', 'password' => 'motdepasse'])
            );
        }

        $response->assertStatus(429);
        $this->assertStringContainsString('application/json', (string) $response->headers->get('Content-Type'));
    }
}
