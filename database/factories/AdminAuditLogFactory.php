<?php

namespace Database\Factories;

use App\Models\AdminAuditLog;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AdminAuditLog>
 */
class AdminAuditLogFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'auditable_type' => 'App\\Models\\Plan',
            'auditable_id' => '1',
            'event' => 'updated',
        ];
    }
}
