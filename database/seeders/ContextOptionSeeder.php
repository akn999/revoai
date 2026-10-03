<?php

namespace Database\Seeders;

use App\Models\ContextOption;
use Illuminate\Database\Seeder;

class ContextOptionSeeder extends Seeder
{
    public function run(): void
    {
        $options = [
            'industry' => ['fashion' => ['أزياء', 'Fashion'], 'electronics' => ['إلكترونيات', 'Electronics'], 'beauty' => ['جمال وعناية', 'Beauty and care'], 'home' => ['المنزل', 'Home and living'], 'food' => ['أغذية', 'Food and drink'], 'health' => ['صحة', 'Health'], 'sports' => ['رياضة', 'Sports'], 'toys' => ['ألعاب', 'Toys and kids'], 'books' => ['كتب وقرطاسية', 'Books and stationery'], 'other' => ['أخرى', 'Other']],
            'audience' => ['men' => ['رجال', 'Men'], 'women' => ['نساء', 'Women'], 'kids' => ['أطفال', 'Kids'], 'teens' => ['مراهقون', 'Teens'], 'families' => ['عائلات', 'Families'], 'businesses' => ['شركات', 'Businesses']],
            'brand_tone' => ['friendly' => ['ودود', 'Friendly'], 'professional' => ['احترافي', 'Professional'], 'luxury' => ['فاخر', 'Luxury'], 'playful' => ['مرح', 'Playful'], 'minimal' => ['بسيط', 'Minimal']],
            'photography_style' => ['studio' => ['استوديو', 'Studio'], 'lifestyle' => ['أسلوب حياة', 'Lifestyle'], 'minimal' => ['بسيط', 'Minimal'], 'outdoor' => ['خارجي', 'Outdoor'], 'flat_lay' => ['تصوير علوي', 'Flat lay']],
        ];

        foreach ($options as $field => $entries) {
            $sort = 0;

            foreach ($entries as $key => [$ar, $en]) {
                ContextOption::query()->firstOrCreate(
                    ['field' => $field, 'key' => $key],
                    ['label_ar' => $ar, 'label_en' => $en, 'sort' => $sort++, 'active' => true],
                );
            }
        }
    }
}
