<?php

namespace Database\Factories;

use App\Models\ContentVersion;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ContentVersion>
 */
class ContentVersionFactory extends Factory
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
            'field' => 'name',
            'value' => 'القيمة',
            'source' => 'ai',
        ];
    }
}
