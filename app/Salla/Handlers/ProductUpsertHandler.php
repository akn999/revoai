<?php

namespace App\Salla\Handlers;

use App\Models\AppEvent;
use App\Models\Product;
use App\Salla\HandlerResult;
use App\Services\MerchantService;
use App\Sync\ProductSyncService;

/**
 * product.created and product.updated (FR-SYN-004).
 */
class ProductUpsertHandler implements AppEventHandler
{
    public function __construct(private MerchantService $merchants, private ProductSyncService $sync) {}

    public function handle(AppEvent $event): HandlerResult
    {
        $merchant = $this->merchants->resolve($event);
        $payload = (array) $event->data();

        if (! isset($payload['id'])) {
            return HandlerResult::Ignored;
        }

        $existing = Product::withTrashed()->where('merchant_id', $merchant->merchant_id)->where('salla_product_id', (int) $payload['id'])->first();

        if ($existing?->last_event_at && $event->event_created_at && $event->event_created_at->lt($existing->last_event_at)) {
            return HandlerResult::Ignored;
        }

        $product = $this->sync->upsert($merchant, $payload, eventAt: $event->event_created_at);
        $this->sync->queueTranslations($merchant, $product);

        return HandlerResult::Processed;
    }
}
