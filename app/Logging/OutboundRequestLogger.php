<?php

namespace App\Logging;

use App\Enums\ActivityLevel;
use Closure;
use GuzzleHttp\Promise\Create;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Throwable;

/**
 * Guzzle middleware logging every outbound call made through Laravel's Http client.
 */
class OutboundRequestLogger
{
    public function __invoke(callable $handler): Closure
    {
        return function (RequestInterface $request, array $options) use ($handler) {
            if (! config('activity-log.outbound.enabled', true)) {
                return $handler($request, $options);
            }

            $startedAt = hrtime(true);

            try {
                $promise = $handler($request, $options);
            } catch (Throwable $exception) {
                $this->record($request, null, $startedAt, $exception);

                throw $exception;
            }

            return $promise->then(
                function (ResponseInterface $response) use ($request, $startedAt) {
                    $this->record($request, $response->getStatusCode(), $startedAt);

                    return $response;
                },
                function (mixed $reason) use ($request, $startedAt) {
                    $this->record($request, null, $startedAt, $reason instanceof Throwable ? $reason : null);

                    return Create::rejectionFor($reason);
                },
            );
        };
    }

    private function record(RequestInterface $request, ?int $status, int $startedAt, ?Throwable $error = null): void
    {
        $uri = $request->getUri();
        $url = $uri->getScheme().'://'.$uri->getHost().($uri->getPort() ? ':'.$uri->getPort() : '').$uri->getPath();
        parse_str($uri->getQuery(), $query);

        $pending = Activity::channel('outbound')
            ->bySystem()
            ->withoutRequestContext()
            ->http($request->getMethod(), $url, $status, (int) ((hrtime(true) - $startedAt) / 1_000_000))
            ->with(['query_keys' => array_keys($query)]);

        if ($error) {
            $pending->withException($error);
        }

        $pending->log(
            'http.request',
            $request->getMethod().' '.$url.' → '.($status ?? 'failed'),
            $status === null ? ActivityLevel::Error : ActivityLevel::fromStatusCode($status),
        );
    }
}
