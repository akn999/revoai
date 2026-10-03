<?php

namespace Database\Factories;

use App\Models\Product;
use App\Models\ProductContext;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProductContext>
 */
class ProductContextFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'product_id' => Product::factory(),
            'merchant_id' => fn (array $attributes) => Product::find($attributes['product_id'])->merchant_id,
            'stale' => true,
        ];
    }
}
