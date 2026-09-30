<?php

namespace App\Logging;

use App\Enums\ActivityLevel;
use App\Logging\Enrichers\ActivityEnricher;
use App\Models\ActivityLog;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Single entry point for writing to the database activity log.
 *
 * Writing never throws: if the row cannot be stored the failure goes to the fallback log channel.
 */
class ActivityLogger
{
    private static bool $writing = false;

    public function __construct(private Container $container, private ContextSanitizer $sanitizer) {}

    public function channel(string $channel): PendingActivity
    {
        return new PendingActivity($this, $channel);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public function log(ActivityLevel|string $level, string $channel, string $action, ?string $message = null, array $context = []): ?ActivityLog
    {
        return $this->write([
            'level' => $level,
            'channel' => $channel,
            'action' => $action,
            'message' => $message,
            'context' => $context,
        ]);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public function debug(string $channel, string $action, ?string $message = null, array $context = []): ?ActivityLog
    {
        return $this->log(ActivityLevel::Debug, $channel, $action, $message, $context);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public function info(string $channel, string $action, ?string $message = null, array $context = []): ?ActivityLog
    {
        return $this->log(ActivityLevel::Info, $channel, $action, $message, $context);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public function notice(string $channel, string $action, ?string $message = null, array $context = []): ?ActivityLog
    {
        return $this->log(ActivityLevel::Notice, $channel, $action, $message, $context);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public function warning(string $channel, string $action, ?string $message = null, array $context = []): ?ActivityLog
    {
        return $this->log(ActivityLevel::Warning, $channel, $action, $message, $context);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public function error(string $channel, string $action, ?string $message = null, array $context = []): ?ActivityLog
    {
        return $this->log(ActivityLevel::Error, $channel, $action, $message, $context);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public function critical(string $channel, string $action, ?string $message = null, array $context = []): ?ActivityLog
    {
        return $this->log(ActivityLevel::Critical, $channel, $action, $message, $context);
    }

    /**
     * Store a fully prepared entry after enrichment, redaction and size limiting.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function write(array $attributes): ?ActivityLog
    {
        if (! config('activity-log.enabled', true) || self::$writing) {
            return null;
        }

        self::$writing = true;

        try {
            return ActivityLog::query()->create($this->prepare($attributes));
        } catch (Throwable $exception) {
            $this->reportFailure($exception, $attributes);

            return null;
        } finally {
            self::$writing = false;
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function prepare(array $attributes): array
    {
        foreach ((array) config('activity-log.enrichers', []) as $enricher) {
            $attributes = $this->resolveEnricher($enricher)->enrich($attributes);
        }

        unset($attributes['skip_request']);

        $context = $this->sanitizer->sanitize((array) ($attributes['context'] ?? []));

        return [
            ...$attributes,
            'channel' => Str::limit(trim((string) ($attributes['channel'] ?? '')) ?: 'general', 32, ''),
            'action' => Str::limit(trim((string) ($attributes['action'] ?? '')) ?: 'unspecified', 96, ''),
            'level' => ActivityLevel::coerce($attributes['level'] ?? null),
            'message' => isset($attributes['message']) ? mb_scrub((string) $attributes['message']) : null,
            'context' => $context === [] ? null : $this->sanitizer->limit($context),
            'created_at' => $attributes['created_at'] ?? now(),
        ];
    }

    private function resolveEnricher(string $class): ActivityEnricher
    {
        return $this->container->make($class);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function reportFailure(Throwable $exception, array $attributes): void
    {
        try {
            Log::channel(config('activity-log.fallback_channel', 'stderr'))->error('Activity log write failed: '.$exception->getMessage(), [
                'channel' => $attributes['channel'] ?? null,
                'action' => $attributes['action'] ?? null,
            ]);
        } catch (Throwable) {
            // Nothing else can be done; logging must never break the caller.
        }
    }
}
