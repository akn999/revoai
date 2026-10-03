<?php

namespace Database\Factories;

use App\Models\ImageGeneration;
use App\Models\Merchant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ImageGeneration>
 */
class ImageGenerationFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'merchant_id' => fn () => Merchant::factory()->create()->merchant_id,
            'source_url' => 'https://cdn.salla.sa/p/1.jpg',
            'source_key' => '1:abc',
            'variants' => 1,
            'status' => 'queued',
            'credits' => 20,
        ];
    }
}
