<?php

namespace Database\Factories;

use App\Models\PromptDefault;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PromptDefault>
 */
class PromptDefaultFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'key' => fake()->unique()->slug(1),
            'label' => fake()->words(2, true),
            'body' => fake()->sentence(),
        ];
    }
}
