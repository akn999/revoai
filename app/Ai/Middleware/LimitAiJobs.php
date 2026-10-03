<?php

namespace App\Ai\Middleware;

use Closure;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Queue middleware: at most N jobs per store run at once, and the whole platform stays
 * under a per-minute provider rate (FR-AI-009). Both limits come from configuration.
 */
class LimitAiJobs
{
    public function __construct(private int $merchantId, private string $provider = 'ai') {}

    public function handle(object $job, Closure $next): mixed
    {
        $limit = max(1, (int) config('revo.limits.bulk_concurrency'));
        $rate = max(1, (int) config('revo.limits.ai_global_rate_per_minute'));
        $key = "ai-global:{$this->provider}";

        if (RateLimiter::tooManyAttempts($key, $rate)) {
            return $job->release(RateLimiter::availableIn($key));
        }

        $lock = null;

        for ($slot = 1; $slot <= $limit; $slot++) {
            $candidate = Cache::lock("ai-slot:{$this->merchantId}:{$slot}", 600);

            if ($candidate->get()) {
                $lock = $candidate;

                break;
            }
        }

        if ($lock === null) {
            return $job->release(10);
        }

        RateLimiter::hit($key, 60);

        try {
            return $next($job);
        } finally {
            $lock->release();
        }
    }
}
