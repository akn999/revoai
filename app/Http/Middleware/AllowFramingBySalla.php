<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The embedded app may only be framed by the Salla dashboard origins (NFR-SEC-004).
 */
class AllowFramingBySalla
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $origins = implode(' ', (array) config('revo.salla.dashboard_origins', []));
        $response->headers->set('Content-Security-Policy', "frame-ancestors {$origins}");

        return $response;
    }
}
