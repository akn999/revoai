<?php

namespace Database\Factories;

use App\Enums\ActivityLevel;
use App\Models\ActivityLog;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ActivityLog>
 */
class ActivityLogFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'channel' => 'system',
            'action' => 'test.action',
            'level' => ActivityLevel::Info,
            'message' => fake()->sentence(),
            'context' => [],
            'created_at' => now(),
        ];
    }

    public function olderThanDays(int $days): static
    {
        return $this->state(fn () => ['created_at' => now()->subDays($days)->subMinute()]);
    }
}
