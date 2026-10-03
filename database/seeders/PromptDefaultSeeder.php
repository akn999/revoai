<?php

namespace Database\Seeders;

use App\Models\PromptDefault;
use Illuminate\Database\Seeder;

class PromptDefaultSeeder extends Seeder
{
    public function run(): void
    {
        $prompts = [
            PromptDefault::PRODUCT_TONE => ['Product content tone', 'Write product content in a clear, persuasive and trustworthy voice for online shoppers. Be accurate, never invent specifications or claims, keep sentences short, and stay consistent with the store\'s brand tone.'],
            PromptDefault::IMAGE_GENERAL => ['Image general prompt', 'First understand what the product in the image is and how it should look. Keep the product\'s shape, proportions, colors, printed text and logos exactly as they are.'],
            PromptDefault::IMAGE_EDITING => ['Image editing prompt', 'Place the product on a clean, light studio background with soft, even lighting and a natural shadow.'],
            PromptDefault::CHAT_SYSTEM => ['Chat system prompt', 'You are the store\'s friendly customer support assistant. Answer only from the store\'s products and policies, be concise, and offer to hand the conversation to a human when you cannot help.'],
        ];

        foreach ($prompts as $key => [$label, $body]) {
            PromptDefault::query()->firstOrCreate(['key' => $key], ['label' => $label, 'body' => $body]);
        }
    }
}
