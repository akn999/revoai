<?php

namespace Database\Factories;

use App\Models\AiModel;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AiModel>
 */
class AiModelFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'provider' => AiModel::BEDROCK,
            'provider_model_id' => 'test.model-'.fake()->unique()->numberBetween(1, 99999),
            'name_ar' => 'نموذج',
            'name_en' => 'Test model',
            'features' => ['product_content', 'field_regeneration'],
            'capabilities' => ['vision' => false, 'tool_use' => true],
            'prices' => ['product_content' => 8, 'field_regeneration' => 2],
            'cost_rates' => ['input_per_1k' => 0.003, 'output_per_1k' => 0.015],
            'active' => true,
            'default_for' => [],
        ];
    }

    public function image(): static
    {
        return $this->state(fn () => [
            'provider' => AiModel::FAL,
            'features' => ['image_edit'],
            'capabilities' => ['vision' => false, 'tool_use' => false],
            'prices' => ['image_edit' => 20],
            'cost_rates' => ['per_image' => 0.04],
        ]);
    }

    public function defaultFor(string ...$features): static
    {
        return $this->state(fn (array $attributes) => ['default_for' => array_values($features)]);
    }

    public function inactive(): static
    {
        return $this->state(['active' => false]);
    }
}
