<?php

namespace App\Http\Controllers\Api\Concerns;

use App\Models\Merchant;
use Illuminate\Http\Request;

trait ResolvesMerchant
{
    protected function merchant(Request $request): Merchant
    {
        return $request->attributes->get('merchant');
    }

    protected function sallaUserId(Request $request): ?int
    {
        $id = $request->attributes->get('salla_user_id');

        return $id !== null ? (int) $id : null;
    }
}
