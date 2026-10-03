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

        $valid = $secret == $request->header('Authorization');

        if (! $valid) {
            Activity::channel('webhook')->bySystem()
                ->with(['strategy' => $request->header('X-Salla-Security-Strategy')])
                ->warning('salla.signature_invalid', 'Rejected webhook with an invalid signature');
        }

        abort_unless($valid, 401, 'Invalid Salla webhook signature');

        return $next($request);
    }
}
