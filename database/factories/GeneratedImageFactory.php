<?php

namespace Database\Factories;

use App\Models\GeneratedImage;
use App\Models\Merchant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GeneratedImage>
 */
class GeneratedImageFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'merchant_id' => fn () => Merchant::factory()->create()->merchant_id,
            'source' => 'generated',
            'disk' => 'local',
            'path' => 'generated/x.png',
            'mime' => 'image/png',
            'bytes' => 1000,
            'status' => 'draft',
        ];
    }
}
