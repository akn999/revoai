<?php

namespace App\Http\Middleware;

use App\Logging\Activity;
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

        if (! $valid) {
            Activity::channel('webhook')->bySystem()
                ->with(['strategy' => $request->header('X-Salla-Security-Strategy')])
                ->warning('salla.signature_invalid', 'Rejected webhook with an invalid signature');
        }

        abort_unless($valid, 401, 'Invalid Salla webhook signature');

        return $next($request);
    }
}
