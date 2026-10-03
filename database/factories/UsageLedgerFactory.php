<?php

namespace Database\Factories;

use App\Models\UsageLedger;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<UsageLedger>
 */
class UsageLedgerFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'merchant_id' => fake()->numberBetween(100000000, 999999999),
            'feature' => 'product_content',
            'action' => 'product_content',
            'provider' => 'bedrock',
            'provider_model_id' => 'test.model',
            'input_tokens' => 100,
            'output_tokens' => 50,
            'provider_cost_usd' => 0.001,
            'credits_charged' => 0,
            'internal' => false,
            'status' => 'ok',
            'latency_ms' => 120,
        ];
    }
}
