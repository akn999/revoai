<?php

namespace Database\Factories;

use App\Models\Merchant;
use App\Models\ModerationEvent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ModerationEvent>
 */
class ModerationEventFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'merchant_id' => fn () => Merchant::factory()->create()->merchant_id,
            'subject_type' => 'edit_instruction',
            'provider' => 'bedrock',
            'decision' => 'allowed',
        ];
    }
}
