<?php

namespace App\Logging\Enrichers;

use App\Support\CurrentMerchant;
use Illuminate\Http\Request;

class MerchantEnricher implements ActivityEnricher
{
    public function __construct(private CurrentMerchant $current) {}

    public function enrich(array $attributes): array
    {
        if (isset($attributes['merchant_id'])) {
            return $attributes;
        }

        $request = app()->bound('request') ? app('request') : null;
        $fromRequest = $request instanceof Request ? $request->attributes->get('salla_merchant_id') : null;

        $attributes['merchant_id'] = $this->current->id() ?? ($fromRequest !== null ? (int) $fromRequest : null);

        return $attributes;
    }
}
