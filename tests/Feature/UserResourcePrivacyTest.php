<?php

namespace Tests\Feature;

use App\Http\Resources\UserResource;
use App\Models\User;
use Database\Factories\UserFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * Minimisation des données personnelles (cahier des charges §23, §34).
 *
 * Règle : l'e-mail et le téléphone ne sont renvoyés qu'au propriétaire du
 * compte, ou à un membre du personnel habilité (support). Un tiers ne les voit
 * jamais. La règle s'applique au niveau de la ressource, donc quelle que soit
 * la route qui la sérialise.
 */
class UserResourcePrivacyTest extends TestCase
{
    use RefreshDatabase;

    /** Sérialise une ressource du point de vue d'un observateur donné. */
    private function serializeFor(?User $viewer, User $target): array
    {
        $request = Request::create('/api/v1/me', 'GET');

        if ($viewer !== null) {
            $request->setUserResolver(fn () => $viewer);
        }

        // `resolve()` (et non `toArray()`) : c'est lui qui retire les clés
        // conditionnelles (`when(...)`) dont la condition est fausse.
        return (new UserResource($target))->resolve($request);
    }

    public function test_a_third_party_never_sees_contact_details(): void
    {
        /** @var User $owner */
        $owner = UserFactory::new()->create();
        /** @var User $other */
        $other = UserFactory::new()->create();

        $payload = $this->serializeFor($other, $owner);

        $this->assertArrayNotHasKey('email', $payload);
        $this->assertArrayNotHasKey('phone', $payload);

        // Les informations non sensibles restent disponibles.
        $this->assertSame($owner->first_name, $payload['first_name']);
    }

    public function test_an_anonymous_visitor_never_sees_contact_details(): void
    {
        /** @var User $owner */
        $owner = UserFactory::new()->create();

        $payload = $this->serializeFor(null, $owner);

        $this->assertArrayNotHasKey('email', $payload);
        $this->assertArrayNotHasKey('phone', $payload);
    }

    public function test_the_owner_sees_their_own_contact_details(): void
    {
        /** @var User $owner */
        $owner = UserFactory::new()->create();

        $payload = $this->serializeFor($owner, $owner);

        $this->assertSame($owner->email, $payload['email']);
        $this->assertSame($owner->phone, $payload['phone']);
    }

    public function test_staff_sees_contact_details_for_support(): void
    {
        /** @var User $owner */
        $owner = UserFactory::new()->create();
        /** @var User $staff */
        $staff = UserFactory::new()->admin()->create();

        $payload = $this->serializeFor($staff, $owner);

        $this->assertSame($owner->email, $payload['email']);
    }

    /** Le mot de passe et le secret TOTP ne sont jamais sérialisés, pour personne. */
    public function test_secrets_are_never_serialized(): void
    {
        /** @var User $owner */
        $owner = UserFactory::new()->create(['totp_secret' => 'JBSWY3DPEHPK3PXP']);

        foreach ([$owner, null, UserFactory::new()->admin()->create()] as $viewer) {
            $payload = $this->serializeFor($viewer, $owner);

            $this->assertArrayNotHasKey('password', $payload);
            $this->assertArrayNotHasKey('totp_secret', $payload);
            $this->assertArrayNotHasKey('remember_token', $payload);
        }
    }
}
