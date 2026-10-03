<?php

namespace Database\Factories;

use App\Models\Merchant;
use App\Models\StoreContextEntry;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StoreContextEntry>
 */
class StoreContextEntryFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'merchant_id' => fn () => Merchant::factory()->create()->merchant_id,
            'label' => fake()->words(2, true),
            'text' => fake()->sentence(),
            'sort' => 0,
        ];
    }
}
