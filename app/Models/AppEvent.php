<?php

namespace App\Models;

use App\Enums\AppEventStatus;
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
     * Events older than 12 months are archived by the scheduled prune.
     *
     * @return Builder<AppEvent>
     */
    public function prunable(): Builder
    {
        return static::where('created_at', '<', now()->subMonths(12));
    }
}
