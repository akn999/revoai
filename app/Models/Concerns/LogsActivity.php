<?php

namespace App\Models\Concerns;

use App\Logging\Activity;
use App\Logging\ContextSanitizer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Logs created / updated / deleted / restored events of the model to the activity log.
 *
 * Optional model properties:
 *  - $activityChannel: channel name, defaults to "model"
 *  - $activityIgnore: attributes whose changes are not logged
 *
 * @mixin Model
 */
trait LogsActivity
{
    public static function bootLogsActivity(): void
    {
        static::created(fn (self $model) => $model->logModelActivity('created'));
        static::updated(fn (self $model) => $model->logModelActivity('updated'));
        static::deleted(fn (self $model) => $model->logModelActivity('deleted'));

        if (method_exists(static::class, 'restored')) {
            static::restored(fn (self $model) => $model->logModelActivity('restored'));
        }
    }

    protected function logModelActivity(string $event): void
    {
        if (! config('activity-log.models.enabled', true)) {
            return;
        }

        $changes = match ($event) {
            'created' => $this->maskedAttributes($this->getAttributes()),
            'updated' => $this->updatedChanges(),
            default => [],
        };

        if ($event === 'updated' && $changes === []) {
            return;
        }

        $name = class_basename($this);
        $merchantId = $this->getAttribute('merchant_id');

        Activity::channel($this->activityChannel ?? 'model')
            ->on($this)
            ->forMerchant(is_numeric($merchantId) ? (int) $merchantId : null)
            ->with($changes === [] ? [] : [$event === 'updated' ? 'changes' : 'attributes' => $changes])
            ->log(Str::snake($name).'.'.$event, "{$name} #{$this->getKey()} {$event}");
    }

    /**
     * @return array<string, array{old: mixed, new: mixed}|string>
     */
    private function updatedChanges(): array
    {
        $ignored = [
            $this->getUpdatedAtColumn(),
            method_exists($this, 'getDeletedAtColumn') ? $this->getDeletedAtColumn() : null,
            ...($this->activityIgnore ?? []),
        ];
        $changes = [];

        foreach (array_diff_key($this->getChanges(), array_flip(array_filter($ignored))) as $key => $value) {
            $changes[$key] = $this->isHiddenForActivity($key)
                ? ContextSanitizer::REDACTED
                : ['old' => $this->getOriginal($key), 'new' => $value];
        }

        return $changes;
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function maskedAttributes(array $attributes): array
    {
        foreach ($attributes as $key => $value) {
            if ($this->isHiddenForActivity($key)) {
                $attributes[$key] = ContextSanitizer::REDACTED;
            }
        }

        return $attributes;
    }

    private function isHiddenForActivity(string $key): bool
    {
        return in_array($key, $this->getHidden(), true);
    }
}
