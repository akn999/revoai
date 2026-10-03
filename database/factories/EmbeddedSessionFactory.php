<?php

namespace Database\Factories;

use App\Models\EmbeddedSession;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EmbeddedSession>
 */
class EmbeddedSessionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'token_hash' => hash('sha256', fake()->unique()->sha256()),
            'merchant_id' => fake()->numberBetween(100000000, 999999999),
            'salla_user_id' => fake()->numberBetween(1000, 999999),
            'last_used_at' => now(),
            'expires_at' => now()->addHours(8),
        ];
    }
}
