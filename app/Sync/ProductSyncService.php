<?php

namespace App\Sync;

use App\Enums\MerchantStatus;
use App\Logging\Activity;
use App\Models\Merchant;
use App\Models\Product;
use App\Models\ProductContext;
use App\Models\ProductImage;
use App\Models\ProductTranslation;
use Carbon\CarbonInterface;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Date;

/**
 * Pulls the catalog from Salla once, then keeps it current from webhooks. Every write is an
 * upsert, so a restarted run or a replayed event never duplicates anything.
 */
class ProductSyncService
{
    public function __construct(private SallaApi $api) {}

    /**
     * Sync one page of the initial run. Returns the next page number, or null when the run is complete.
     */
    public function syncPage(Merchant $merchant, int $page, CarbonInterface $runStartedAt, bool $fullResync): ?int
    {
        $result = $this->api->listProducts($merchant, $page);

        foreach ($result['products'] as $payload) {
            $product = $this->upsert($merchant, $payload, syncedAt: $runStartedAt);
            $this->queueTranslations($merchant, $product, debounce: false);
        }

        $merchant->forceFill(['sync_pages_done' => $page, 'sync_total_pages' => $result['total_pages']])->save();

        if ($page < $result['total_pages']) {
            return $page + 1;
        }

        $this->finish($merchant, $runStartedAt, $fullResync);

        return null;
    }

    private function finish(Merchant $merchant, CarbonInterface $runStartedAt, bool $fullResync): void
    {
        if ($fullResync) {
            // Products missing from Salla since the uninstall are marked deleted (FR-INS-005).
            Product::query()
                ->where('merchant_id', $merchant->merchant_id)
                ->where(fn ($query) => $query->whereNull('synced_at')->orWhere('synced_at', '<', $runStartedAt))
                ->each(fn (Product $product) => $product->delete());
        }

        $merchant->forceFill(['status' => MerchantStatus::Active, 'sync_finished_at' => now()])->save();

        Activity::channel('system')->bySystem()->forMerchant($merchant->merchant_id)
            ->info('sync.completed', 'Initial product sync finished');
    }

    /**
     * Create or update a product and its default-language text and images from a Salla payload.
     *
     * @param  array<string, mixed>  $payload
     */
    public function upsert(Merchant $merchant, array $payload, ?string $language = null, ?CarbonInterface $eventAt = null, ?CarbonInterface $syncedAt = null): Product
    {
        $language ??= $merchant->default_language;

        $product = Product::withTrashed()->firstOrNew([
            'merchant_id' => $merchant->merchant_id,
            'salla_product_id' => (int) $payload['id'],
        ]);

        $isDefaultLanguage = $language === $merchant->default_language;
        $columns = $this->columns($payload);

        if ($isDefaultLanguage || ! $product->exists) {
            $product->fill($columns);
        }

        $product->synced_at = $syncedAt ? Carbon::instance($syncedAt) : now();

        if ($eventAt) {
            $product->last_event_at = Carbon::instance($eventAt);
        }

        if ($product->trashed()) {
            $product->restore();
        }

        $hash = $this->relevantHash($payload);
        $changed = $isDefaultLanguage && $product->relevant_hash !== $hash;

        if ($isDefaultLanguage) {
            $product->relevant_hash = $hash;
        }

        $product->save();

        $this->saveTranslation($product, $language, $payload);

        if ($isDefaultLanguage && array_key_exists('images', $payload)) {
            $changed = $this->syncImages($product, (array) $payload['images']) || $changed;
        }

        $context = ProductContext::query()->firstOrCreate(['product_id' => $product->id], ['merchant_id' => $merchant->merchant_id, 'stale' => true]);

        if ($changed && ! $context->wasRecentlyCreated) {
            $context->forceFill(['stale' => true])->save();
        }

        return $product;
    }

    /**
     * Replace the Salla-hosted image set of a product (FR-SYN-006). Returns whether anything changed.
     * Tombstoned images (deleted by the merchant in Revo) are never restored.
     *
     * @param  array<int, array<string, mixed>>  $images
     */
    public function syncImages(Product $product, array $images): bool
    {
        $changed = false;
        $incoming = collect($images)->filter(fn ($image) => is_array($image) && ($image['type'] ?? null) === 'image' && isset($image['id']));
        $tombstoned = $product->images()->whereNotNull('tombstoned_at')->pluck('salla_image_id')->all();

        foreach ($incoming as $image) {
            if (in_array((int) $image['id'], $tombstoned, true)) {
                continue;
            }

            $record = ProductImage::query()->firstOrNew(['product_id' => $product->id, 'salla_image_id' => (int) $image['id']]);
            $record->fill([
                'merchant_id' => $product->merchant_id,
                'url' => (string) ($image['url'] ?? ''),
                'alt' => $image['alt'] ?? null,
                'main' => (bool) ($image['main'] ?? false),
                'sort' => (int) ($image['sort'] ?? 0),
                'three_d_image_url' => $image['three_d_image_url'] ?? null,
            ]);

            $changed = $changed || $record->isDirty();
            $record->save();
        }

        $removed = $product->images()
            ->whereNull('tombstoned_at')
            ->whereNotIn('salla_image_id', $incoming->pluck('id')->map(fn ($id) => (int) $id)->all())
            ->delete();

        return $changed || $removed > 0;
    }

    /**
     * Re-fetch the text of every enabled language other than the default one.
     */
    public function fetchTranslations(Merchant $merchant, Product $product): void
    {
        foreach ($this->otherLanguages($merchant) as $language) {
            $payload = $this->api->getProduct($merchant, $product->salla_product_id, $language);

            if ($payload !== null) {
                $this->saveTranslation($product, $language, $payload);
            }
        }

        $product->context?->forceFill(['stale' => true])->save();
    }

    /**
     * The free per-product "Re-sync": default language, images and every other language.
     */
    public function resync(Merchant $merchant, Product $product): void
    {
        $payload = $this->api->getProduct($merchant, $product->salla_product_id, $merchant->default_language);

        if ($payload === null) {
            $product->delete();

            return;
        }

        $product = $this->upsert($merchant, $payload);
        $this->fetchTranslations($merchant, $product);
    }

    public function markDeleted(Merchant $merchant, int $sallaProductId, ?CarbonInterface $eventAt = null): bool
    {
        $product = Product::query()->where('merchant_id', $merchant->merchant_id)->where('salla_product_id', $sallaProductId)->first();

        if (! $product) {
            return false;
        }

        if ($eventAt) {
            $product->forceFill(['last_event_at' => $eventAt])->save();
        }

        return (bool) $product->delete();
    }

    /**
     * Queue the other-language fetches. Webhook bursts are debounced to one round per product per minute.
     */
    public function queueTranslations(Merchant $merchant, Product $product, bool $debounce = true): void
    {
        if ($this->otherLanguages($merchant) === []) {
            return;
        }

        if ($debounce) {
            $window = (int) config('revo.salla.language_fetch_debounce_seconds');

            if (! Cache::add("revo:translations:{$merchant->merchant_id}:{$product->id}", 1, $window)) {
                return;
            }

            FetchProductTranslations::dispatch($merchant->merchant_id, $product->id)
                ->delay(now()->addSeconds($window))
                ->onQueue(config('salla.queue'));

            return;
        }

        FetchProductTranslations::dispatch($merchant->merchant_id, $product->id)->onQueue(config('salla.queue'));
    }

    /**
     * @return array<int, string>
     */
    private function otherLanguages(Merchant $merchant): array
    {
        return array_values(array_diff($merchant->enabled_languages ?? config('revo.product.languages'), [$merchant->default_language]));
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function saveTranslation(Product $product, string $language, array $payload): ProductTranslation
    {
        $translation = ProductTranslation::query()->firstOrNew(['product_id' => $product->id, 'lang' => $language]);

        $translation->fill([
            'merchant_id' => $product->merchant_id,
            'name' => $payload['name'] ?? null,
            'description' => $payload['description'] ?? null,
            'promotion_title' => data_get($payload, 'promotion.title'),
            'subtitle' => data_get($payload, 'promotion.sub_title'),
            'metadata_title' => data_get($payload, 'metadata.title'),
            'metadata_description' => data_get($payload, 'metadata.description'),
            'metadata_url' => data_get($payload, 'metadata.url'),
            'fetched_at' => now(),
        ])->save();

        return $translation;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function columns(array $payload): array
    {
        $money = fn (mixed $value): mixed => is_array($value) ? ($value['amount'] ?? null) : $value;

        return [
            'sku' => $payload['sku'] ?? null,
            'mpn' => $payload['mpn'] ?? null,
            'gtin' => $payload['gtin'] ?? null,
            'type' => $payload['type'] ?? null,
            'status' => $payload['status'] ?? null,
            'price' => $money($payload['price'] ?? null),
            'sale_price' => $money($payload['sale_price'] ?? null),
            'currency' => data_get($payload, 'price.currency'),
            'quantity' => isset($payload['quantity']) ? (int) $payload['quantity'] : null,
            'hide_quantity' => (bool) ($payload['hide_quantity'] ?? false),
            'is_available' => (bool) ($payload['is_available'] ?? true),
            'categories' => $payload['categories'] ?? null,
            'brand' => $payload['brand'] ?? null,
            'tags' => $payload['tags'] ?? null,
            'options' => $payload['options'] ?? null,
            'skus' => $payload['skus'] ?? null,
            'urls' => $payload['urls'] ?? null,
            'promotion' => $payload['promotion'] ?? null,
            'metadata' => $payload['metadata'] ?? null,
            'raw' => $payload,
        ];
    }

    /**
     * Hash of the fields that matter for AI context; a change marks the product context stale.
     *
     * @param  array<string, mixed>  $payload
     */
    private function relevantHash(array $payload): string
    {
        $relevant = Arr::only($payload, ['name', 'description', 'sku', 'status', 'quantity', 'categories', 'brand', 'tags', 'options', 'promotion', 'metadata']);
        $relevant['price'] = data_get($payload, 'price.amount', $payload['price'] ?? null);
        $relevant['images'] = collect($payload['images'] ?? [])
            ->filter(fn ($image) => is_array($image) && ($image['type'] ?? null) === 'image')
            ->map(fn (array $image) => [$image['id'] ?? null, $image['url'] ?? null, $image['alt'] ?? null])
            ->values()->all();

        return hash('sha256', (string) json_encode($relevant, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR));
    }

    public static function parseEventTime(mixed $value): ?CarbonInterface
    {
        return $value ? Date::parse($value) : null;
    }
}
