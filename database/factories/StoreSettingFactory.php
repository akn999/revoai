<?php

namespace Database\Factories;

use App\Models\Merchant;
use App\Models\StoreSetting;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StoreSetting>
 */
class StoreSettingFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'merchant_id' => fn () => Merchant::factory()->create()->merchant_id,
            'auto_publish' => false,
            'description_length' => 'medium',
            'description_structure' => 'paragraphs',
            'default_variants' => 1,
        ];
    }
}
