<?php

namespace Database\Factories;

use App\Models\Merchant;
use App\Models\StorePrompt;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StorePrompt>
 */
class StorePromptFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'merchant_id' => fn () => Merchant::factory()->create()->merchant_id,
            'key' => 'product_tone',
            'body' => fake()->sentence(),
        ];
    }
}
