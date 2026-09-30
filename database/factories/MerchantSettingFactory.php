<?php

namespace Database\Factories;

use App\Models\Merchant;
use App\Models\MerchantSetting;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MerchantSetting>
 */
class MerchantSettingFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'merchant_id' => fn () => Merchant::factory()->create()->merchant_id,
            'settings' => ['name' => fake()->name()],

        ];
    }
}
