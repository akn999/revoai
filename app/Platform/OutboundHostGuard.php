<?php

namespace App\Platform;

use Closure;
use Illuminate\Support\Str;
use Psr\Http\Message\RequestInterface;
use RuntimeException;

/**
 * Guzzle middleware refusing requests to hosts outside the configured allow-list (NFR-SEC-005).
 */
class OutboundHostGuard
{
    public function __invoke(callable $handler): Closure
    {
        return function (RequestInterface $request, array $options) use ($handler) {
            if (config('revo.outbound.enforce_allowlist', true)) {
                $host = strtolower($request->getUri()->getHost());

                if (! Str::is((array) config('revo.outbound.allowed_hosts', []), $host)) {
                    throw new RuntimeException("Outbound requests to [{$host}] are not allowed.");
                }
            }

            return $handler($request, $options);
        };
    }
}
