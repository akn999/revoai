<?php

namespace Database\Factories;

use App\Models\ImageAnalysis;
use App\Models\Merchant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ImageAnalysis>
 */
class ImageAnalysisFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'merchant_id' => fn () => Merchant::factory()->create()->merchant_id,
            'source_key' => fake()->sha1(),
            'analysis' => 'A product on a plain background.',
        ];
    }
}
