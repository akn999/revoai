<?php

namespace Database\Factories;

use App\Models\ContextOption;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ContextOption>
 */
class ContextOptionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'field' => 'industry',
            'key' => fake()->unique()->slug(1),
            'label_ar' => 'خيار',
            'label_en' => fake()->word(),
            'sort' => 0,
            'active' => true,
        ];
    }
}
