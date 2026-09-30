<?php

namespace App\Logging\Enrichers;

use Illuminate\Http\Request;
use Illuminate\Support\Str;

class RequestEnricher implements ActivityEnricher
{
    public function enrich(array $attributes): array
    {
        $request = empty($attributes['skip_request']) ? $this->currentRequest() : null;

        if (! $request) {
            return $attributes;
        }

        $attributes['ip_address'] ??= $request->ip();
        $attributes['user_agent'] ??= Str::limit((string) $request->userAgent(), 512, '');
        $attributes['http_method'] ??= $request->method();
        $attributes['url'] ??= Str::limit($request->url(), 2048, '');

        return $attributes;
    }

    /**
     * Console commands and queue workers get a placeholder request, so only routed requests count.
     */
    private function currentRequest(): ?Request
    {
        $request = app()->bound('request') ? app('request') : null;

        return $request instanceof Request && $request->route() !== null ? $request : null;
    }
}
