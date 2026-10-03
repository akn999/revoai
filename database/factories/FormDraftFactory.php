<?php

namespace Database\Factories;

use App\Models\FormDraft;
use App\Models\Merchant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FormDraft>
 */
class FormDraftFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'merchant_id' => fn () => Merchant::factory()->create()->merchant_id,
            'salla_user_id' => 1,
            'form_key' => 'settings.prompts',
            'payload' => ['text' => 'draft'],
        ];
    }
}
