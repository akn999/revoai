<?php

namespace Database\Seeders;

use App\Models\Preset;
use Illuminate\Database\Seeder;

class PresetSeeder extends Seeder
{
    public function run(): void
    {
        $presets = [
            ['إزالة الخلفية', 'Background removal', 'Remove the background completely and leave the product on a transparent or plain white background.'],
            ['خلفية بيضاء', 'White background', 'Place the product on a pure white studio background with a soft natural shadow.'],
            ['استبدال الخلفية', 'Background replacement', 'Replace the background with a clean, neutral backdrop that suits the product and the store\'s brand.'],
            ['رفع الدقة', 'Upscale', 'Increase the resolution and sharpness without changing the product or adding new details.'],
            ['تصحيح الإضاءة والألوان', 'Lighting and color correction', 'Correct the lighting and white balance so the product\'s real colors are shown accurately.'],
            ['مشهد حياتي', 'Lifestyle scene', 'Place the product in a realistic lifestyle scene that fits its use. Any person must be modestly dressed.'],
        ];

        foreach ($presets as $index => [$ar, $en, $prompt]) {
            Preset::query()->firstOrCreate(
                ['merchant_id' => null, 'name_en' => $en],
                ['name_ar' => $ar, 'prompt' => $prompt, 'active' => true, 'sort' => $index],
            );
        }
    }
}
