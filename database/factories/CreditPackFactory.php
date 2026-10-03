<?php

namespace Database\Factories;

use App\Models\CreditPack;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CreditPack>
 */
class CreditPackFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'salla_addon_slug' => 'credits_'.fake()->unique()->numberBetween(100, 99999),
            'credits' => 500,
            'name_ar' => 'باقة 500',
            'name_en' => '500 credits',
            'active' => true,
        ];
    }
}
