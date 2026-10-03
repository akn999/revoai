<?php

namespace Database\Factories;

use App\Models\Product;
use App\Models\ProductTranslation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProductTranslation>
 */
class ProductTranslationFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'product_id' => Product::factory(),
            'merchant_id' => fn (array $attributes) => Product::find($attributes['product_id'])->merchant_id,
            'lang' => 'ar',
            'name' => fake()->words(3, true),
            'description' => '<p>'.fake()->sentence().'</p>',
        ];
    }
}
