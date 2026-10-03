<?php

namespace Database\Factories;

use App\Models\Merchant;
use App\Models\SettingsAudit;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SettingsAudit>
 */
class SettingsAuditFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'merchant_id' => fn () => Merchant::factory()->create()->merchant_id,
            'group' => 'general',
        ];
    }
}
