<?php

namespace App\Sync;

use App\Models\Merchant;
use App\Models\Product;
use App\Support\CurrentMerchant;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * The free "Re-sync" action: costs zero credits (FR-SYN-007).
 */
class ResyncProduct implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public int $merchantId, public int $productId) {}

    public function handle(ProductSyncService $sync, CurrentMerchant $context): void
    {
        $context->set($this->merchantId);
        $merchant = Merchant::findBySallaId($this->merchantId);
        $product = Product::query()->find($this->productId);

        if ($merchant && $product) {
            $sync->resync($merchant, $product);
        }
    }
}
