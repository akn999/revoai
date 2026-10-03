<?php

namespace Database\Factories;

use App\Models\MerchantWallet;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MerchantWallet>
 */
class MerchantWalletFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return ['merchant_id' => fake()->unique()->numberBetween(100000000, 999999999), 'balance' => 0, 'reserved' => 0];
    }
}
