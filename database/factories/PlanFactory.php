<?php

namespace Database\Factories;

use App\Models\Plan;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Plan>
 */
class PlanFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'slug' => fake()->unique()->slug(2),
            'name' => fake()->words(2, true),
            'item_type' => 'plan',
            'salla_plan_name' => fake()->unique()->words(2, true),
            'is_active' => true,

        ];
    }

    public function addon(): static
    {
        return $this->state(fn () => [
            'item_type' => 'addon',
            'salla_plan_name' => null,
            'salla_item_slug' => 'addon_'.fake()->unique()->slug(1),
        ]);
    }
}
