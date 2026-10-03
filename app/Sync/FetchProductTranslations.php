<?php

namespace App\Sync;

use App\Models\Merchant;
use App\Models\Product;
use App\Support\CurrentMerchant;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class FetchProductTranslations implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    /** @var array<int, int> */
    public array $backoff = [10, 30, 60, 300];

    public function __construct(public int $merchantId, public int $productId) {}

    public function handle(ProductSyncService $sync, CurrentMerchant $context): void
    {
        $context->set($this->merchantId);
        $merchant = Merchant::findBySallaId($this->merchantId);
        $product = Product::query()->find($this->productId);

        if ($merchant && $product && ! in_array($merchant->status->value, ['uninstalled', 'purged'], true)) {
            $sync->fetchTranslations($merchant, $product);
        }
    }
}
