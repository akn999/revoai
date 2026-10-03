<?php

namespace App\Salla\Handlers;

use App\Models\AppEvent;
use App\Models\Product;
use App\Salla\HandlerResult;
use App\Services\MerchantService;
use App\Sync\ProductSyncService;

/**
 * product.image.updated sends the full image list; the product's Salla-hosted set is replaced (FR-SYN-006).
 */
class ProductImagesUpdatedHandler implements AppEventHandler
{
    public function __construct(private MerchantService $merchants, private ProductSyncService $sync) {}

    public function handle(AppEvent $event): HandlerResult
    {
        $merchant = $this->merchants->resolve($event);
        $productId = (int) ($event->data('product_id') ?? $event->data('id') ?? 0);
        $product = Product::query()->where('merchant_id', $merchant->merchant_id)->where('salla_product_id', $productId)->first();

        if (! $product) {
            return HandlerResult::Ignored;
        }

        if ($product->last_event_at && $event->event_created_at && $event->event_created_at->lt($product->last_event_at)) {
            return HandlerResult::Ignored;
        }

        $changed = $this->sync->syncImages($product, (array) $event->data('images', []));

        $product->forceFill(['last_event_at' => $event->event_created_at])->save();

        if ($changed) {
            $product->context?->forceFill(['stale' => true])->save();
        }

        return HandlerResult::Processed;
    }
}
