<?php

namespace App\Models;

use App\Enums\ActivityLevel;
use Database\Factories\ActivityLogFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * @property int $id
 * @property string $channel
 * @property string $action
 * @property ActivityLevel $level
 * @property string|null $message
 * @property string|null $actor_type
 * @property string|null $actor_id
 * @property string|null $subject_type
 * @property string|null $subject_id
 * @property int|null $merchant_id
 * @property array<string, mixed>|null $context
 * @property string|null $correlation_id
 * @property string|null $ip_address
 * @property string|null $user_agent
 * @property string|null $http_method
 * @property string|null $url
 * @property int|null $status_code
 * @property int|null $duration_ms
 * @property Carbon $created_at
 */
#[UseFactory(ActivityLogFactory::class)]
class ActivityLog extends Model
{
    /** @use HasFactory<ActivityLogFactory> */
    use HasFactory, MassPrunable;

    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected $dateFormat = 'Y-m-d H:i:s.u';

    public function getTable(): string
    {
        return config('activity-log.table', 'activity_logs');
    }

    public function getConnectionName(): ?string
    {
        return config('activity-log.connection');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'level' => ActivityLevel::class,
            'context' => 'array',
            'merchant_id' => 'integer',
            'created_at' => 'datetime:Y-m-d H:i:s.u',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (): void {
            throw new LogicException('Activity log entries are append-only.');
        });
    }

    /**
     * Rows older than the configured retention are pruned; 0 days keeps everything.
     *
     * @return Builder<static>
     */
    public function prunable(): Builder
    {
        $days = (int) config('activity-log.retention_days', 14);

        return $days > 0
            ? static::query()->where('created_at', '<', now()->subDays($days))
            : static::query()->whereRaw('1 = 0');
    }

    /**
     * @param  Builder<ActivityLog>  $query
     * @return Builder<ActivityLog>
     */
    public function scopeChannel(Builder $query, string $channel): Builder
    {
        return $query->where('channel', $channel);
    }

    /**
     * @param  Builder<ActivityLog>  $query
     * @return Builder<ActivityLog>
     */
    public function scopeForSubject(Builder $query, Model $subject): Builder
    {
        return $query->where('subject_type', $subject->getMorphClass())->where('subject_id', (string) $subject->getKey());
    }

    /**
     * @param  Builder<ActivityLog>  $query
     * @return Builder<ActivityLog>
     */
    public function scopeForMerchant(Builder $query, int $merchantId): Builder
    {
        return $query->where('merchant_id', $merchantId);
    }
}
