<?php

namespace App\Products;

use App\Logging\Activity;
use App\Models\ContentGeneration;
use App\Models\ContentVersion;
use App\Models\Merchant;
use App\Models\Product;
use App\Models\ProductTranslation;
use App\Products\Exceptions\PushFailed;
use App\Sync\SallaApi;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Pushes approved text to Salla (FR-PRD-012/013): only the approved fields, in the field's language,
 * SEO fields nested in `metadata`. Pushing never costs credits and a failed push keeps the draft.
 */
class ProductPushService
{
    public function __construct(private SallaApi $api) {}

    /**
     * @param  array<string, string|null>  $values  field => value (approved fields only)
     * @param  array<string, string>  $sources  field => version source (ai, manual_edit or revert)
     *
     * @throws PushFailed
     */
    public function push(Merchant $merchant, Product $product, string $language, array $values, ?ContentGeneration $generation = null, array $sources = [], ?int $sallaUserId = null): void
    {
        $values = array_intersect_key($values, array_flip(FieldLimits::TEXT_FIELDS));

        if ($values === []) {
            return;
        }

        $translation = ProductTranslation::query()->firstOrNew(['product_id' => $product->id, 'lang' => $language]);

        $this->captureOriginals($merchant, $product, $language, $translation, array_keys($values), $sallaUserId);

        try {
            $this->api->updateProduct($merchant, $product->salla_product_id, $this->body($values), $language);
        } catch (Throwable $exception) {
            Activity::channel('system')->bySystem()->forMerchant($merchant->merchant_id)->on($product)->withException($exception)
                ->warning('product.push_failed', 'Pushing product content to Salla failed');

            throw new PushFailed($exception);
        }

        DB::transaction(function () use ($merchant, $product, $language, $values, $generation, $sources, $sallaUserId, $translation): void {
            $translation->fill(['merchant_id' => $merchant->merchant_id, ...$values])->save();

            foreach ($values as $field => $value) {
                ContentVersion::query()->create([
                    'merchant_id' => $merchant->merchant_id,
                    'product_id' => $product->id,
                    'lang' => $language,
                    'field' => $field,
                    'value' => $value,
                    'source' => $sources[$field] ?? ContentVersion::AI,
                    'generation_id' => $generation?->id,
                    'pushed_at' => now(),
                    'salla_user_id' => $sallaUserId,
                ]);
            }

            $product->context?->forceFill(['stale' => true])->save();
        });
    }

    /**
     * One-click revert: pushes an earlier value and records the revert as a new history entry.
     *
     * @throws PushFailed
     */
    public function revert(Merchant $merchant, ContentVersion $version, ?int $sallaUserId = null): void
    {
        $product = Product::query()->findOrFail($version->product_id);

        $this->push(
            $merchant,
            $product,
            $version->lang,
            [$version->field => $version->value],
            sources: [$version->field => ContentVersion::REVERT],
            sallaUserId: $sallaUserId,
        );
    }

    /**
     * Keep the Salla value that was live before the first Revo push of each field, so it can always be restored.
     *
     * @param  array<int, string>  $fields
     */
    private function captureOriginals(Merchant $merchant, Product $product, string $language, ProductTranslation $translation, array $fields, ?int $sallaUserId): void
    {
        foreach ($fields as $field) {
            $exists = ContentVersion::query()
                ->where('product_id', $product->id)->where('lang', $language)->where('field', $field)
                ->exists();

            if (! $exists) {
                ContentVersion::query()->create([
                    'merchant_id' => $merchant->merchant_id,
                    'product_id' => $product->id,
                    'lang' => $language,
                    'field' => $field,
                    'value' => $translation->{FieldLimits::COLUMNS[$field]},
                    'source' => ContentVersion::SALLA_ORIGINAL,
                    'salla_user_id' => $sallaUserId,
                ]);
            }
        }
    }

    /**
     * @param  array<string, string|null>  $values
     * @return array<string, mixed>
     */
    private function body(array $values): array
    {
        $body = [];

        foreach ($values as $field => $value) {
            match ($field) {
                'name', 'description', 'promotion_title', 'subtitle' => $body[$field] = $value,
                'metadata_title' => $body['metadata']['title'] = $value,
                'metadata_description' => $body['metadata']['description'] = $value,
                'metadata_url' => $body['metadata']['url'] = $value,
                default => null,
            };
        }

        return $body;
    }
}
