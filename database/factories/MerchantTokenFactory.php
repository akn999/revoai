<?php

namespace Database\Factories;

use App\Models\Merchant;
use App\Models\MerchantToken;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MerchantToken>
 */
class MerchantTokenFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'merchant_id' => Merchant::factory(),
            'access_token' => fake()->sha256(),
            'refresh_token' => fake()->sha256(),
            'token_type' => 'bearer',
            'scope' => 'settings.read offline_access',
            'expires_at' => now()->addDays(14),

        ];
    }

    public function expiring(): static
    {
        return $this->state(['expires_at' => now()->addDay()]);
    }
}
