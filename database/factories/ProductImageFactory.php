<?php

namespace Database\Factories;

use App\Models\Product;
use App\Models\ProductImage;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProductImage>
 */
class ProductImageFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'product_id' => Product::factory(),
            'merchant_id' => fn (array $attributes) => Product::find($attributes['product_id'])->merchant_id,
            'salla_image_id' => fake()->unique()->numberBetween(1000000, 999999999),
            'url' => 'https://cdn.salla.sa/'.fake()->uuid().'.jpg',
            'main' => false,
            'sort' => 0,
        ];
    }
}
