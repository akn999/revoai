<?php

namespace App\Products;

use App\Models\Merchant;
use App\Models\Product;
use App\Models\ProductContext;
use App\Models\ProductImage;
use App\Moderation\ModerationService;
use App\Settings\PromptService;
use App\Settings\SettingsService;
use App\Settings\StoreContextCompiler;

/**
 * Composes product-content prompts on the server only, from templates and the store's own data
 * (FR-AI-007, FR-PRD-004). The browser never sends a composed prompt.
 */
class ContentPromptComposer
{
    private const LANGUAGE_NAMES = ['ar' => 'Arabic', 'en' => 'English'];

    public function __construct(
        private PromptService $prompts,
        private StoreContextCompiler $storeContext,
        private SettingsService $settings,
        private ModerationService $moderation,
    ) {}

    /**
     * @param  array<int, string>  $fields
     */
    public function system(Merchant $merchant, Product $product, ProductContext $context, string $language, array $fields, ?string $keywords): string
    {
        $settings = $this->settings->settingsFor($merchant->merchant_id);
        [$min, $max] = config("revo.product.description_words.{$settings->description_length}");
        $limits = collect($fields)->filter(fn (string $field) => FieldLimits::limit($field) !== null)
            ->map(fn (string $field) => "{$field} ≤ ".FieldLimits::limit($field).' characters')->implode('; ');

        $lines = [
            $this->prompts->effective($merchant->merchant_id, 'product_tone'),
            '',
            'Task: write product content in '.(self::LANGUAGE_NAMES[$language] ?? $language).' for an online store.',
            '- Return only the requested fields.',
            '- Never invent specifications, materials, sizes or claims that are not in the product facts.',
        ];

        if ($limits !== '') {
            $lines[] = "- Hard limits: {$limits}. Stay under every limit.";
        }

        if (in_array('description', $fields, true)) {
            $lines[] = '- description: HTML using only h2, h3, p, ul, ol, li, strong, em, u, s and br. About '.$min.'-'.$max.' words. '
                .($settings->description_structure === 'bullets' ? 'Build it mainly from lists (ul/li).' : 'Write it mainly as paragraphs.');
        }

        if (in_array('metadata_url', $fields, true)) {
            $lines[] = '- metadata_url: a slug only, words joined by hyphens, no spaces or slashes'.($language === 'en' ? ', lowercase Latin letters' : ', Arabic letters allowed').'.';
        }

        if (in_array('alt', $fields, true)) {
            $lines[] = '- alt: one short alt text per listed image, each at most '.FieldLimits::limit('alt').' characters.';
        }

        $storeContext = $this->storeContext->compile($merchant->merchant_id, 'products', $language);

        if ($storeContext !== '') {
            $lines[] = '';
            $lines[] = "Store context:\n{$storeContext}";
        }

        $lines[] = '';
        $lines[] = "Product context:\n".$context->summary;

        if (filled($keywords)) {
            $lines[] = '';
            $lines[] = 'Target keywords (use naturally): '.trim((string) $keywords);
        }

        $lines[] = '';
        $lines[] = $this->moderation->platformRules();

        return implode("\n", $lines);
    }

    /**
     * @param  array<int, string>  $fields
     */
    public function user(Product $product, string $language, array $fields, ?string $instruction): string
    {
        $current = $product->translation($language);
        $values = [];

        foreach ($fields as $field) {
            if (isset(FieldLimits::COLUMNS[$field])) {
                $values[$field] = $current?->{FieldLimits::COLUMNS[$field]};
            }
        }

        $message = 'Requested fields: '.implode(', ', $fields).".\nCurrent values in Salla: ".json_encode($values, JSON_UNESCAPED_UNICODE);

        if (in_array('alt', $fields, true)) {
            $images = ProductImage::query()->where('product_id', $product->id)->whereNull('tombstoned_at')->orderBy('sort')->get(['salla_image_id', 'alt']);
            $message .= "\nImages: ".json_encode($images->map(fn ($image) => ['image_id' => $image->salla_image_id, 'current_alt' => $image->alt])->all(), JSON_UNESCAPED_UNICODE);
        }

        if (filled($instruction)) {
            $message .= "\nInstruction: ".trim((string) $instruction);
        }

        return $message;
    }

    /**
     * JSON Schema of the structured answer: only the selected fields.
     *
     * @param  array<int, string>  $fields
     * @return array<string, mixed>
     */
    public function schema(array $fields): array
    {
        $properties = [];

        foreach ($fields as $field) {
            $properties[$field] = $field === 'alt'
                ? ['type' => 'array', 'items' => ['type' => 'object', 'properties' => ['image_id' => ['type' => 'integer'], 'alt' => ['type' => 'string']], 'required' => ['image_id', 'alt']]]
                : ['type' => 'string'];
        }

        return ['type' => 'object', 'properties' => $properties, 'required' => array_values($fields)];
    }
}
