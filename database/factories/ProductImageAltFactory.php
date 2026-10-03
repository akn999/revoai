<?php

namespace Database\Factories;

use App\Models\ProductImage;
use App\Models\ProductImageAlt;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProductImageAlt>
 */
class ProductImageAltFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'product_image_id' => ProductImage::factory(),
            'merchant_id' => fn (array $attributes) => ProductImage::find($attributes['product_image_id'])->merchant_id,
            'lang' => 'ar',
            'alt' => 'نص بديل',
        ];
    }
}
