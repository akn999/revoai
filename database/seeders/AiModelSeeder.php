<?php

namespace Database\Seeders;

use App\Models\AiModel;
use Illuminate\Database\Seeder;

/**
 * Starter catalog. The provider model ids are placeholders: super admins replace them
 * in the panel with the models the Bedrock region and fal.ai account actually offer.
 */
class AiModelSeeder extends Seeder
{
    public function run(): void
    {
        $models = [
            [
                'provider' => AiModel::BEDROCK, 'provider_model_id' => 'anthropic.claude-sonnet-4-20250514-v1:0',
                'name_ar' => 'نموذج المحتوى', 'name_en' => 'Content model',
                'features' => ['product_content', 'field_regeneration', 'text_moderation', 'chat'],
                'capabilities' => ['vision' => true, 'tool_use' => true],
                'prices' => ['product_content' => 8, 'field_regeneration' => 2],
                'cost_rates' => ['input_per_1k' => 0.003, 'output_per_1k' => 0.015],
                'default_for' => ['product_content', 'field_regeneration', 'text_moderation', 'chat'], 'sort' => 0,
            ],
            [
                'provider' => AiModel::FAL, 'provider_model_id' => 'fal-ai/flux-pro/kontext',
                'name_ar' => 'تعديل الصور', 'name_en' => 'Image editor',
                'features' => ['image_edit'],
                'capabilities' => ['vision' => false, 'tool_use' => false],
                'prices' => ['image_edit' => 20],
                'param_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'seed' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 4294967295],
                        'guidance_scale' => ['type' => 'number', 'minimum' => 1, 'maximum' => 20],
                        'num_inference_steps' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 50],
                        'aspect_ratio' => ['type' => 'string', 'enum' => ['1:1', '4:3', '3:4', '16:9', '9:16']],
                        'output_format' => ['type' => 'string', 'enum' => ['jpeg', 'png', 'webp']],
                        'negative_prompt' => ['type' => 'string', 'maxLength' => 500],
                    ],
                ],
                'cost_rates' => ['per_image' => 0.04],
                'default_for' => ['image_edit'], 'sort' => 0,
            ],
            [
                'provider' => AiModel::FAL, 'provider_model_id' => 'fal-ai/moondream2',
                'name_ar' => 'تحليل الصور', 'name_en' => 'Image analysis',
                'features' => ['image_analysis'],
                'capabilities' => ['vision' => true, 'tool_use' => false],
                'prices' => [],
                'cost_rates' => ['per_image' => 0.005],
                'default_for' => ['image_analysis'], 'sort' => 0,
            ],
            [
                'provider' => AiModel::FAL, 'provider_model_id' => 'fal-ai/imageutils/nsfw',
                'name_ar' => 'مراجعة الصور', 'name_en' => 'Image moderation',
                'features' => ['image_moderation'],
                'capabilities' => ['vision' => true, 'tool_use' => false],
                'prices' => [],
                'cost_rates' => ['per_image' => 0.002],
                'default_for' => ['image_moderation'], 'sort' => 0,
            ],
        ];

        foreach ($models as $model) {
            AiModel::query()->firstOrCreate(
                ['provider' => $model['provider'], 'provider_model_id' => $model['provider_model_id']],
                $model + ['active' => true],
            );
        }
    }
}
