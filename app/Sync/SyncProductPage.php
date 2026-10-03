<?php

namespace App\Sync;

use App\Models\Merchant;
use App\Support\CurrentMerchant;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Date;

/**
 * Syncs one page, then queues the next. Salla's pagination expires after 15 minutes, so a run that
 * gets that old restarts from page 1; upserts make the restart safe (FR-SYN-001).
 */
class SyncProductPage implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    /** @var array<int, int> */
    public array $backoff = [10, 30, 60, 300];

    public function __construct(public int $merchantId, public int $page, public string $runStartedAt, public bool $fullResync = false) {}

    public function handle(ProductSyncService $sync, CurrentMerchant $context): void
    {
        $context->set($this->merchantId);
        $merchant = Merchant::findBySallaId($this->merchantId);

        if (! $merchant || $merchant->status->value === 'uninstalled' || $merchant->status->value === 'purged') {
            return;
        }

        $runStartedAt = Date::parse($this->runStartedAt);

        if ($this->page > 1 && $runStartedAt->diffInMinutes(now(), true) >= (int) config('revo.salla.sync_window_minutes')) {
            self::dispatch($this->merchantId, 1, now()->toIso8601String(), $this->fullResync)->onQueue(config('salla.queue'));

            return;
        }

        $next = $sync->syncPage($merchant, $this->page, $runStartedAt, $this->fullResync);

        if ($next !== null) {
            self::dispatch($this->merchantId, $next, $this->runStartedAt, $this->fullResync)->onQueue(config('salla.queue'));
        }
    }
}
