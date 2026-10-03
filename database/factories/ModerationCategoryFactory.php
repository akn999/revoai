<?php

namespace Database\Factories;

use App\Models\ModerationCategory;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ModerationCategory>
 */
class ModerationCategoryFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'key' => fake()->unique()->slug(1),
            'name_ar' => 'فئة',
            'name_en' => fake()->words(2, true),
            'description' => fake()->sentence(),
            'instruction' => fake()->sentence(),
            'active' => true,
            'sort' => 0,
        ];
    }
}
