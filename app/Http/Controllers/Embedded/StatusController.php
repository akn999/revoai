<?php

namespace App\Http\Controllers\Embedded;

use App\Http\Controllers\Controller;
use App\Models\Merchant;
use App\Models\Subscription;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StatusController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $merchant = Merchant::findBySallaId((int) $request->attributes->get('salla_merchant_id'))
            ->load('currentPlan');

        return response()->json([
            'merchant_id' => $merchant->merchant_id,
            'status' => $merchant->status->value,
            'plan' => $merchant->currentPlan ? $this->summary($merchant->currentPlan) : null,
            'addons' => $merchant->activeAddons()->get()->map(fn (Subscription $addon) => $this->summary($addon))->all(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function summary(Subscription $subscription): array
    {
        return [
            'name' => $subscription->plan_name,
            'item_key' => $subscription->item_key,
            'status' => $subscription->status->value,
            'billing_cycle' => $subscription->billing_cycle->value,
            'quantity' => $subscription->quantity,
            'ends_at' => $subscription->ends_at?->toIso8601String(),
        ];
    }
}
