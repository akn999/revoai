<?php

namespace Database\Factories;

use App\Models\AppFeedback;
use App\Models\Merchant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AppFeedback>
 */
class AppFeedbackFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'merchant_id' => Merchant::factory(),
            'rating' => fake()->numberBetween(1, 5),
            'rated_by' => fake()->name(),
            'comment' => fake()->sentence(),

        ];
    }
}
