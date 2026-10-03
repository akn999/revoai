<?php

namespace App\Salla\Handlers;

use App\Models\AppEvent;
use App\Models\Product;
use App\Salla\HandlerResult;
use App\Services\MerchantService;
use App\Sync\ProductSyncService;

class ProductDeletedHandler implements AppEventHandler
{
    public function __construct(private MerchantService $merchants, private ProductSyncService $sync) {}

    public function handle(AppEvent $event): HandlerResult
    {
        $merchant = $this->merchants->resolve($event);
        $id = (int) ($event->data('id') ?? 0);

        $existing = Product::withTrashed()->where('merchant_id', $merchant->merchant_id)->where('salla_product_id', $id)->first();

        if (! $existing || ($existing->last_event_at && $event->event_created_at && $event->event_created_at->lt($existing->last_event_at))) {
            return HandlerResult::Ignored;
        }

        return $this->sync->markDeleted($merchant, $id, $event->event_created_at) ? HandlerResult::Processed : HandlerResult::Ignored;
    }
}
