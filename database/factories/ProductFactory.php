<?php

namespace Database\Factories;

use App\Models\Merchant;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'merchant_id' => fn () => Merchant::factory()->create()->merchant_id,
            'salla_product_id' => fake()->unique()->numberBetween(1000000, 999999999),
            'sku' => fake()->bothify('SKU-####'),
            'type' => 'product',
            'status' => 'sale',
            'price' => fake()->randomFloat(2, 5, 500),
            'currency' => 'SAR',
            'quantity' => 10,
            'is_available' => true,
        ];
    }
}
