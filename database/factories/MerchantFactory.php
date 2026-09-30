<?php

namespace Database\Factories;

use App\Enums\MerchantStatus;
use App\Models\Merchant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Merchant>
 */
class MerchantFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'merchant_id' => fake()->unique()->numberBetween(100000000, 999999999),
            'status' => MerchantStatus::Pending,
            'store_type' => 'live',

        ];
    }

    public function active(): static
    {
        return $this->state(['status' => MerchantStatus::Active]);
    }

    public function uninstalled(): static
    {
        return $this->state(['status' => MerchantStatus::Uninstalled, 'uninstalled_at' => now()]);
    }
}
