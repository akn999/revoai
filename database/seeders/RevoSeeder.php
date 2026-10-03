<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Creates every default the product needs; safe to run repeatedly (NFR-MNT-005).
 */
class RevoSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            PlanSeeder::class,
            CreditPackSeeder::class,
            AiModelSeeder::class,
            PromptDefaultSeeder::class,
            PresetSeeder::class,
            ModerationCategorySeeder::class,
            ContextOptionSeeder::class,
        ]);
    }
}
