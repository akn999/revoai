<?php

namespace App\Logging\Enrichers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ActorEnricher implements ActivityEnricher
{
    public function enrich(array $attributes): array
    {
        if (isset($attributes['actor_type'])) {
            return $attributes;
        }

        if ($user = Auth::user()) {
            $attributes['actor_type'] = $user->getMorphClass();
            $attributes['actor_id'] = (string) $user->getAuthIdentifier();

            return $attributes;
        }

        $request = app()->bound('request') ? app('request') : null;

        if ($request instanceof Request && $request->attributes->has('salla_merchant_id')) {
            $attributes['actor_type'] = 'salla_merchant';
            $attributes['actor_id'] = (string) $request->attributes->get('salla_merchant_id');
        }

        return $attributes;
    }
}
