<?php

namespace Database\Seeders;

use App\Models\CreditPack;
use Illuminate\Database\Seeder;

class CreditPackSeeder extends Seeder
{
    public function run(): void
    {
        foreach ([500, 2000, 5000] as $index => $credits) {
            CreditPack::query()->firstOrCreate(
                ['salla_addon_slug' => "revo-credits-{$credits}"],
                ['credits' => $credits, 'name_ar' => "باقة {$credits} رصيد", 'name_en' => "{$credits} credits", 'active' => true, 'sort' => $index],
            );
        }
    }
}
