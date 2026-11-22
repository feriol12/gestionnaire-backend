<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
            // BE-03 : mêmes valeurs que les défauts de la migration, présentes
            // aussi sur l'instance en mémoire (Sanctum::actingAs ne relit pas la base).
            'administrative_status' => 'active',
            'auth_version' => 1,
        ];
    }

    /** BE-03 : compte suspendu (état fictif de test). */
    public function suspended(): static
    {
        return $this->state(fn (array $attributes) => [
            'administrative_status' => 'suspended',
            'auth_version' => 2,
            'suspended_at' => now(),
            'suspended_by' => 1,
        ]);
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }
}
