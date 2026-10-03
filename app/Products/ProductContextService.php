<?php

namespace App\Products;

use App\Ai\AiContext;
use App\Ai\AiGateway;
use App\Ai\Exceptions\AiUnavailable;
use App\Ai\ModelResolver;
use App\Ai\SallaCdn;
use App\Models\ImageAnalysis;
use App\Models\Merchant;
use App\Models\Product;
use App\Models\ProductContext;
use App\Models\ProductImage;
use App\Settings\PromptService;
use Illuminate\Support\Str;

/**
 * Builds and caches the compact product context reused across AI calls (FR-PRD-005). Building it is
 * internal and never charged; image analyses are cached per image version so an unchanged product
 * never calls fal.ai analysis twice.
 */
class ProductContextService
{
    public function __construct(private AiGateway $gateway, private ModelResolver $models, private PromptService $prompts) {}

    public function ensure(Merchant $merchant, Product $product, ?int $sallaUserId = null): ProductContext
    {
        $context = ProductContext::query()->firstOrCreate(['product_id' => $product->id], ['merchant_id' => $merchant->merchant_id, 'stale' => true]);

        if (! $context->stale && $context->built_at) {
            return $context;
        }

        return $this->build($merchant, $product, $context, $sallaUserId);
    }

    private function build(Merchant $merchant, Product $product, ProductContext $context, ?int $sallaUserId): ProductContext
    {
        $analyses = $this->analyses($merchant, $product, $sallaUserId);

        $facts = array_filter([
            'sku' => $product->sku,
            'type' => $product->type,
            'status' => $product->status,
            'price' => $product->price !== null ? "{$product->price} {$product->currency}" : null,
            'quantity' => $product->hide_quantity ? null : $product->quantity,
            'categories' => collect($product->categories ?? [])->pluck('name')->filter()->values()->all(),
            'brand' => data_get($product->brand, 'name'),
            'tags' => $product->tags,
            'options' => collect($product->options ?? [])->map(fn (array $option) => [
                'name' => $option['name'] ?? null,
                'values' => collect($option['values'] ?? [])->pluck('name')->filter()->values()->all(),
            ])->filter(fn (array $option) => $option['name'])->values()->all(),
        ], fn ($value) => $value !== null && $value !== [] && $value !== '');

        $names = $product->translations->mapWithKeys(fn ($translation) => [$translation->lang => $translation->name])->filter()->all();

        $summary = $this->summary($product, $merchant->default_language, $names, $facts, $analyses);

        $context->forceFill([
            'merchant_id' => $merchant->merchant_id,
            'facts' => $facts,
            'summary' => $summary,
            'source_hash' => hash('sha256', ($product->relevant_hash ?? '').'|'.collect($analyses)->keys()->sort()->implode(',')),
            'stale' => false,
            'built_at' => now(),
        ])->save();

        return $context;
    }

    /**
     * One analysis per image version, reused from the cache whenever the image is unchanged.
     *
     * @return array<string, string> source key => analysis text
     */
    private function analyses(Merchant $merchant, Product $product, ?int $sallaUserId): array
    {
        $model = $this->models->defaultFor('image_analysis');

        if ($model === null) {
            return [];
        }

        $context = new AiContext($merchant->merchant_id, 'product_content', 'product_context', $sallaUserId);
        $analyses = [];

        $images = ProductImage::query()->where('product_id', $product->id)->whereNull('tombstoned_at')->orderBy('sort')->limit(4)->get();

        foreach ($images as $image) {
            if (! SallaCdn::isAllowed($image->url)) {
                continue;
            }

            $key = $this->sourceKey($image);
            $cached = ImageAnalysis::query()->where('merchant_id', $merchant->merchant_id)->where('source_key', $key)->first();

            if (! $cached) {
                try {
                    $result = $this->gateway->analyzeImage(
                        $context->for('product_content', 'image_analysis'),
                        $model,
                        $image->url,
                        $this->prompts->effective($merchant->merchant_id, 'image_general'),
                    );
                } catch (AiUnavailable) {
                    continue;
                }

                $cached = ImageAnalysis::query()->create([
                    'merchant_id' => $merchant->merchant_id,
                    'source_key' => $key,
                    'ai_model_id' => $model->id,
                    'analysis' => $result->description,
                ]);
            }

            $analyses[$key] = $cached->analysis;
        }

        return $analyses;
    }

    /**
     * Image version key: Salla image id plus a hash of the URL (FR-IMG-007).
     */
    public function sourceKey(ProductImage $image): string
    {
        return $image->salla_image_id.':'.md5($image->url);
    }

    /**
     * @param  array<string, string>  $names
     * @param  array<string, mixed>  $facts
     * @param  array<string, string>  $analyses
     */
    private function summary(Product $product, string $language, array $names, array $facts, array $analyses): string
    {
        $maxChars = (int) config('revo.limits.product_context_tokens') * 4;
        $lines = [];

        foreach ($names as $language => $name) {
            $lines[] = "Name ({$language}): {$name}";
        }

        foreach ($facts as $key => $value) {
            $lines[] = Str::headline($key).': '.(is_array($value) ? json_encode($value, JSON_UNESCAPED_UNICODE) : $value);
        }

        foreach (array_values($analyses) as $index => $analysis) {
            $lines[] = 'Image '.($index + 1).': '.$analysis;
        }

        // Least relevant first: the description is trimmed to whatever room is left.
        $text = implode("\n", $lines);
        $room = $maxChars - mb_strlen($text) - 20;
        $description = trim(strip_tags((string) $product->translation($language)?->description));

        if ($room > 50 && $description !== '') {
            $text .= "\nCurrent description: ".Str::limit($description, $room, '');
        }

        return mb_strlen($text) > $maxChars ? mb_substr($text, 0, $maxChars) : $text;
    }
}
