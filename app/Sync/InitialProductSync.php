<?php

namespace App\Sync;

use App\Models\Merchant;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Starts the initial (or re-install) catalog sync: page 1 now, each next page chained after the previous one.
 */
class InitialProductSync implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $merchantId, public bool $fullResync = false) {}

    public function handle(): void
    {
        $merchant = Merchant::findBySallaId($this->merchantId);

        if (! $merchant) {
            return;
        }

        $merchant->forceFill(['sync_pages_done' => 0, 'sync_total_pages' => null, 'sync_started_at' => now(), 'sync_finished_at' => null])->save();

        SyncProductPage::dispatch($this->merchantId, 1, now()->toIso8601String(), $this->fullResync)->onQueue(config('salla.queue'));
    }
}
