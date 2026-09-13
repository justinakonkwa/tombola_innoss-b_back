<?php

namespace Database\Factories;

use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;

/**
 * La table `users` utilise des clés UUID et first_name/last_name/phone/country
 * (pas de colonne `name`) : ce factory remplace le factory Laravel par défaut,
 * incompatible avec le schéma.
 *
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    protected $model = User::class;

    /** Le mot de passe est haché une seule fois par processus (Argon2id est coûteux). */
    protected static ?string $password = null;

    public function definition(): array
    {
        return [
            'first_name' => fake()->firstName(),
            'last_name' => fake()->lastName(),
            'phone' => '243'.fake()->unique()->numerify('#########'),
            'email' => fake()->unique()->safeEmail(),
            'country' => 'CD',
            'city' => fake()->city(),
            'password' => static::$password ??= Hash::make('password'),
            'status' => UserStatus::Active,
            'is_admin' => false,
            'locale' => 'fr',
            'email_verified_at' => now(),
        ];
    }

    /** Compte back-office (MFA obligatoire côté middleware). */
    public function admin(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_admin' => true,
        ]);
    }

    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    public function withPassword(string $password): static
    {
        return $this->state(fn (array $attributes) => [
            'password' => Hash::make($password),
        ]);
    }
}
