<?php

namespace Database\Factories;

use App\Models\Preset;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Preset>
 */
class PresetFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'merchant_id' => null,
            'name_ar' => 'قالب',
            'name_en' => fake()->words(2, true),
            'prompt' => 'Remove the background',
            'active' => true,
            'sort' => 0,
        ];
    }

    public function forMerchant(int $merchantId): static
    {
        return $this->state(['merchant_id' => $merchantId]);
    }
}
