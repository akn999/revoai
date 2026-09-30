<?php

namespace App\Http\Middleware;

use App\Salla\SallaClient;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateSallaEmbedded
{
    public function __construct(private SallaClient $client) {}

    /**
     * Verify the embedded-SDK session token with Salla and expose the merchant it belongs to.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken() ?? $request->header('X-Salla-Token');

        abort_unless(is_string($token) && $token !== '', 401, 'Missing Salla session token');

        $identity = $this->client->introspect($token);

        abort_if($identity === null, 401, 'Invalid Salla session token');

        $request->attributes->set('salla_merchant_id', $identity['merchant_id']);

        return $next($request);
    }
}
