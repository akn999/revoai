<?php

namespace App\Models;

use App\Enums\AppEventStatus;
use App\Jobs\ProcessAppEvent;
use App\Logging\Activity;
use Database\Factories\AppEventFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int|null $merchant_id
 * @property string $event
 * @property string $payload_hash
 * @property array<string, mixed> $payload
 * @property Carbon|null $event_created_at
 * @property AppEventStatus $status
 * @property int $attempts
 * @property string|null $error
 * @property Carbon|null $processed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[UseFactory(AppEventFactory::class)]
class AppEvent extends Model
{
    /** @use HasFactory<AppEventFactory> */
    use HasFactory, Prunable;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'status' => AppEventStatus::class,
            'event_created_at' => 'datetime',
            'processed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Merchant, $this>
     */
    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class, 'merchant_id', 'merchant_id');
    }

    public function data(?string $key = null, mixed $default = null): mixed
    {
        return data_get($this->payload, $key ? "data.$key" : 'data', $default);
    }

    /**
     * Raw webhook payloads are kept for the configured number of days (90 by default).
     *
     * @return Builder<AppEvent>
     */
    public function prunable(): Builder
    {
        return static::where('created_at', '<', now()->subDays((int) config('revo.limits.webhook_days', 90)));
    }

    /**
     * Put the event back in the queue as if it had just arrived.
     */
    public function replay(): void
    {
        $this->update(['status' => AppEventStatus::Received, 'attempts' => 0, 'error' => null, 'processed_at' => null]);

        Activity::channel('system')->bySystem()->on($this)->forMerchant($this->merchant_id)
            ->info('salla.event_replayed', "Replayed {$this->event}");

        ProcessAppEvent::dispatch($this->id, $this->merchant_id)->onQueue(config('salla.queue'));
    }
}
