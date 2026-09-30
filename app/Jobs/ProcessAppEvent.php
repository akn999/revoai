<?php

namespace App\Jobs;

use App\Enums\AppEventStatus;
use App\Models\AppEvent;
use App\Salla\AppEventRouter;
use App\Salla\HandlerResult;
use App\Support\CurrentMerchant;
use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class ProcessAppEvent implements ShouldQueue
{
    use Queueable;

    /** Releases from the overlap lock count as attempts, so failures are limited by exceptions instead. */
    public int $tries = 0;

    /** Read from the job payload by the worker, so it is set at dispatch time. */
    public int $maxExceptions;

    public function __construct(public int $appEventId, public int $merchantId)
    {
        $this->maxExceptions = (int) config('salla.max_exceptions', 5);
    }

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return config('salla.backoff', [10, 30, 60, 300, 900]);
    }

    public function retryUntil(): DateTimeInterface
    {
        return now()->addHours(6);
    }

    /**
     * One merchant's events run one at a time, so authorize and subscription.started never race.
     *
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [(new WithoutOverlapping("salla-merchant:{$this->merchantId}"))->releaseAfter(5)->expireAfter(120)];
    }

    public function handle(AppEventRouter $router, CurrentMerchant $context): void
    {
        $event = AppEvent::findOrFail($this->appEventId);

        if (in_array($event->status, [AppEventStatus::Processed, AppEventStatus::Ignored], true)) {
            return;
        }

        $previousMerchant = $context->id();
        $context->set($event->merchant_id);

        try {
            $event->update(['status' => AppEventStatus::Processing, 'attempts' => $event->attempts + 1]);

            $handler = $router->for($event->event);
            $result = $handler
                ? DB::transaction(fn () => $handler->handle($event))
                : HandlerResult::Ignored;

            $event->update([
                'status' => $result === HandlerResult::Ignored ? AppEventStatus::Ignored : AppEventStatus::Processed,
                'processed_at' => now(),
                'error' => null,
            ]);
        } finally {
            $previousMerchant ? $context->set($previousMerchant) : $context->clear();
        }
    }

    public function failed(Throwable $exception): void
    {
        AppEvent::whereKey($this->appEventId)->update([
            'status' => AppEventStatus::Failed,
            'error' => Str::limit($exception->getMessage(), 2000),
        ]);

        Log::error('Salla app event failed', [
            'app_event_id' => $this->appEventId,
            'merchant_id' => $this->merchantId,
            'error' => $exception->getMessage(),
        ]);

        $recentFailures = AppEvent::where('status', AppEventStatus::Failed)
            ->where('updated_at', '>=', now()->subHour())
            ->count();

        if ($recentFailures > config('salla.failed_alert_threshold', 10)) {
            Log::critical('Salla app events are failing in bulk', ['failed_last_hour' => $recentFailures]);
        }
    }
}
