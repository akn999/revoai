<?php

namespace Database\Factories;

use App\Models\ContentGeneration;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ContentGeneration>
 */
class ContentGenerationFactory extends Factory
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
            'kind' => 'full',
            'fields' => ['name', 'description'],
            'status' => 'draft',
            'output' => ['name' => 'اسم', 'description' => '<p>وصف</p>'],
            'credits' => 8,
        ];
    }
}
