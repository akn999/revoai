<?php

namespace App\Http\Middleware;

use App\Enums\ActivityLevel;
use App\Logging\Activity;
use App\Logging\ActivityContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Logs every routed request once the response has been sent, so it adds no latency.
 * Usage: LogRequests::class.':web' — the parameter is the default channel.
 */
class LogRequests
{
    private const STARTED_AT = 'activity_started_at';

    private const CHANNEL = 'activity_channel';

    public function __construct(private ActivityContext $context) {}

    public function handle(Request $request, Closure $next, string $channel = 'web'): Response
    {
        if (! config('activity-log.http.enabled', true)) {
            return $next($request);
        }

        $request->attributes->set(self::STARTED_AT, hrtime(true));
        $request->attributes->set(self::CHANNEL, $channel);

        if ($requestId = $request->header('X-Request-Id')) {
            $this->context->useCorrelationId($requestId);
        }

        $response = $next($request);
        $response->headers->set('X-Request-Id', $this->context->correlationId());

        return $response;
    }

    /**
     * Laravel does not pass middleware parameters to terminate(), so the channel travels on the request.
     */
    public function terminate(Request $request, Response $response): void
    {
        if (! config('activity-log.http.enabled', true) || ! $request->attributes->has(self::STARTED_AT) || $this->isExcluded($request)) {
            return;
        }

        $duration = (int) ((hrtime(true) - $request->attributes->get(self::STARTED_AT)) / 1_000_000);
        $status = $response->getStatusCode();
        $path = '/'.ltrim($request->path(), '/');

        Activity::channel($this->channelFor($request, (string) $request->attributes->get(self::CHANNEL, 'web')))
            ->http($request->method(), $request->url(), $status, $duration)
            ->with(array_filter([
                'route' => $request->route()?->getName(),
                'query' => $request->query() ?: null,
            ]))
            ->log('http.request', $request->method().' '.$path.' → '.$status, ActivityLevel::fromStatusCode($status));
    }

    private function channelFor(Request $request, string $default): string
    {
        foreach ((array) config('activity-log.http.route_channels', []) as $pattern => $channel) {
            if ($request->routeIs($pattern)) {
                return $channel;
            }
        }

        return $default;
    }

    private function isExcluded(Request $request): bool
    {
        return Str::is((array) config('activity-log.http.exclude', []), $request->path());
    }
}
