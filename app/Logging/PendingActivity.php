<?php

namespace App\Logging;

use App\Enums\ActivityLevel;
use App\Models\ActivityLog;
use Illuminate\Database\Eloquent\Model;
use Throwable;

/**
 * Fluent builder: Activity::channel('user')->by($user)->on($model)->with([...])->info('profile.updated').
 */
class PendingActivity
{
    /** @var array<string, mixed> */
    private array $attributes = [];

    /** @var array<string, mixed> */
    private array $context = [];

    public function __construct(private ActivityLogger $logger, string $channel)
    {
        $this->attributes['channel'] = $channel;
    }

    public function by(?Model $actor): static
    {
        if ($actor) {
            $this->attributes['actor_type'] = $actor->getMorphClass();
            $this->attributes['actor_id'] = (string) $actor->getKey();
        }

        return $this;
    }

    /**
     * Mark the entry as done by the system so no user is attached automatically.
     */
    public function bySystem(): static
    {
        $this->attributes['actor_type'] = 'system';
        $this->attributes['actor_id'] = null;

        return $this;
    }

    public function on(?Model $subject): static
    {
        if ($subject) {
            $this->attributes['subject_type'] = $subject->getMorphClass();
            $this->attributes['subject_id'] = (string) $subject->getKey();
        }

        return $this;
    }

    public function forMerchant(?int $merchantId): static
    {
        $this->attributes['merchant_id'] = $merchantId;

        return $this;
    }

    public function correlatedWith(string $correlationId): static
    {
        $this->attributes['correlation_id'] = $correlationId;

        return $this;
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public function with(array $context): static
    {
        $this->context = [...$this->context, ...$context];

        return $this;
    }

    public function withException(Throwable $exception): static
    {
        $this->context['exception'] = (new ContextSanitizer)->exception($exception);

        return $this;
    }

    /**
     * Describe an HTTP exchange on the row's dedicated columns.
     */
    public function http(string $method, string $url, ?int $statusCode = null, ?int $durationMs = null): static
    {
        $this->attributes['http_method'] = $method;
        $this->attributes['url'] = $url;
        $this->attributes['status_code'] = $statusCode;
        $this->attributes['duration_ms'] = $durationMs;

        return $this;
    }

    /**
     * Do not attach the current inbound request (ip, user agent, url) to this entry.
     */
    public function withoutRequestContext(): static
    {
        $this->attributes['skip_request'] = true;

        return $this;
    }

    public function log(string $action, ?string $message = null, ActivityLevel|string $level = ActivityLevel::Info): ?ActivityLog
    {
        return $this->logger->write([
            ...$this->attributes,
            'action' => $action,
            'message' => $message,
            'level' => $level,
            'context' => $this->context,
        ]);
    }

    public function debug(string $action, ?string $message = null): ?ActivityLog
    {
        return $this->log($action, $message, ActivityLevel::Debug);
    }

    public function info(string $action, ?string $message = null): ?ActivityLog
    {
        return $this->log($action, $message, ActivityLevel::Info);
    }

    public function notice(string $action, ?string $message = null): ?ActivityLog
    {
        return $this->log($action, $message, ActivityLevel::Notice);
    }

    public function warning(string $action, ?string $message = null): ?ActivityLog
    {
        return $this->log($action, $message, ActivityLevel::Warning);
    }

    public function error(string $action, ?string $message = null): ?ActivityLog
    {
        return $this->log($action, $message, ActivityLevel::Error);
    }

    public function critical(string $action, ?string $message = null): ?ActivityLog
    {
        return $this->log($action, $message, ActivityLevel::Critical);
    }
}
