<?php

namespace Database\Factories;

use App\Models\Merchant;
use App\Models\StoreContext;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StoreContext>
 */
class StoreContextFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'merchant_id' => fn () => Merchant::factory()->create()->merchant_id,
            'returns_accepted' => false,
        ];
    }
}
