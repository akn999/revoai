<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class VerifySallaWebhook
{
    public function handle(Request $request, Closure $next): Response
    {
        $secret = (string) config('salla.webhook_secret');

        $valid = $secret !== '' && match ($request->header('X-Salla-Security-Strategy')) {
            'Signature' => hash_equals(
                hash_hmac('sha256', $request->getContent(), $secret),
                (string) $request->header('X-Salla-Signature'),
            ),
            'Token' => hash_equals($secret, (string) $request->bearerToken()),
            default => false,
        };

        abort_unless($valid, 401, 'Invalid Salla webhook signature');

        return $next($request);
    }
}
