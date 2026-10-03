<?php

namespace App\Http\Middleware;

use App\Enums\MerchantStatus;
use App\Models\Merchant;
use App\Platform\EmbeddedSessionService;
use App\Support\CurrentMerchant;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticates the merchant app's own session token and binds the request to that merchant.
 */
class AuthenticateEmbeddedSession
{
    public function __construct(private EmbeddedSessionService $sessions, private CurrentMerchant $current) {}

    public function handle(Request $request, Closure $next): Response
    {
        $session = $this->sessions->authenticate($request->bearerToken());

        if ($session === null) {
            abort(401, 'Invalid or expired session');
        }

        $merchant = Merchant::findBySallaId($session->merchant_id);

        abort_if(
            ! $merchant || in_array($merchant->status, [MerchantStatus::Uninstalled, MerchantStatus::Purged], true),
            401,
            'The app is not installed for this store',
        );

        $request->attributes->set('salla_merchant_id', $merchant->merchant_id);
        $request->attributes->set('salla_user_id', $session->salla_user_id);
        $request->attributes->set('salla_session_id', $session->id);
        $request->attributes->set('merchant', $merchant);
        $this->current->set($merchant->merchant_id);

        return $next($request);
    }
}
