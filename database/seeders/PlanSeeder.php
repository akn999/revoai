<?php

namespace Database\Seeders;

use App\Models\Plan;
use Illuminate\Database\Seeder;

class PlanSeeder extends Seeder
{
    public function run(): void
    {
        $plans = [
            'plus' => ['Plus', ['product_content' => true, 'image_editing' => true, 'media_library' => true, 'complaints' => false, 'chat' => false]],
            'pro' => ['Pro', ['product_content' => true, 'image_editing' => true, 'media_library' => true, 'complaints' => true, 'chat' => false]],
            'enterprise' => ['Enterprise', ['product_content' => true, 'image_editing' => true, 'media_library' => true, 'complaints' => true, 'chat' => true]],
        ];

        foreach ($plans as $slug => [$name, $flags]) {
            Plan::query()->firstOrCreate(
                ['slug' => $slug],
                ['name' => $name, 'item_type' => 'plan', 'salla_plan_name' => $name, 'is_active' => true, 'feature_flags' => $flags],
            );
        }
    }
}
