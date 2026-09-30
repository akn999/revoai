<?php

namespace App\Http\Middleware;

use App\Models\Merchant;
use App\Support\CurrentMerchant;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureMerchantCanUseApp
{
    public function handle(Request $request, Closure $next): Response
    {
        $merchant = Merchant::findBySallaId((int) $request->attributes->get('salla_merchant_id'));

        abort_unless($merchant?->canUseApp(), 402, 'Subscription required');

        app(CurrentMerchant::class)->set($merchant->merchant_id);

        return $next($request);
    }
}
