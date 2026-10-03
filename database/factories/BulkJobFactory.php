<?php

namespace Database\Factories;

use App\Models\BulkJob;
use App\Models\Merchant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BulkJob>
 */
class BulkJobFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'merchant_id' => fn () => Merchant::factory()->create()->merchant_id,
            'filter' => ['type' => 'all'],
            'fields' => ['name', 'description'],
            'languages' => ['ar'],
            'total' => 0,
            'done' => 0,
            'failed' => 0,
            'estimate' => 0,
            'status' => 'running',
        ];
    }
}
