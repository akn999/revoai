<?php

namespace Database\Seeders;

use App\Models\ModerationCategory;
use Illuminate\Database\Seeder;

class ModerationCategorySeeder extends Seeder
{
    public function run(): void
    {
        $categories = [
            ['nudity', 'العري والمحتوى الجنسي', 'Nudity and sexual content', 'Nudity, sexual acts and sexualized poses.', 'Block any nudity, sexual act or sexualized pose.', 'Swimwear or lingerie products shown without a person (flat lay, mannequin).'],
            ['minors', 'القاصرون', 'Minors', 'Any minor in a sexual, violent, dangerous or exploitative context, including child abuse.', 'Block any minor in a sexual, violent, dangerous or exploitative context.', 'Children\'s products shown with children in ordinary, modest settings.'],
            ['illegal_activity', 'المخدرات والنشاط غير القانوني', 'Illegal drugs and illegal activity', 'Illegal drugs and their promotion, and other illegal activity.', 'Block illegal drugs, their promotion and other illegal activity.', 'Pharmacy products, medicines and supplements.'],
            ['weapons', 'الأسلحة', 'Weapons', 'Violent or threatening depictions of weapons, and illegal weapons.', 'Block violent or threatening depictions and illegal weapons.', 'Legal products such as hunting gear and knives, shown as products.'],
            ['hate', 'الكراهية والتطرف', 'Hate and extremism', 'Hateful symbols, slurs and extremist content.', 'Block hateful symbols, slurs and extremist content.', null],
            ['violence', 'العنف والدماء', 'Violence and gore', 'Graphic violence, injury and gore.', 'Block graphic violence, injury and gore.', null],
            ['public_figures', 'الشخصيات العامة', 'Public figures', 'Mocking, defaming or demeaning a public figure.', 'Block mocking, defaming or demeaning a public figure.', 'Respectful edits of images that feature a public figure, such as printed merchandise.'],
            ['alcohol', 'الكحول', 'Alcohol', 'Alcoholic drinks and their promotion.', 'Block alcoholic drinks and their promotion.', null],
            ['gambling', 'القمار', 'Gambling', 'Gambling and betting.', 'Block gambling and betting.', null],
            ['modesty', 'احتشام الأشخاص المولّدين', 'Modesty of generated people', 'Generated or edited people in revealing clothing.', 'Block generated or edited people in revealing clothing.', 'Modestly dressed models.'],
        ];

        foreach ($categories as $index => [$key, $ar, $en, $description, $instruction, $allowed]) {
            ModerationCategory::query()->firstOrCreate(
                ['key' => $key],
                ['name_ar' => $ar, 'name_en' => $en, 'description' => $description, 'instruction' => $instruction, 'allowed' => $allowed, 'active' => true, 'sort' => $index],
            );
        }
    }
}
