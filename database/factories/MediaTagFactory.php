<?php

namespace Database\Factories;

use App\Models\MediaTag;
use App\Models\Merchant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MediaTag>
 */
class MediaTagFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'merchant_id' => fn () => Merchant::factory()->create()->merchant_id,
            'name' => fake()->unique()->word(),
        ];
    }
}
