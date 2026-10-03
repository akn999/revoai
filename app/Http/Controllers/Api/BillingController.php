<?php

namespace App\Http\Controllers\Api;

use App\Billing\PurchaseService;
use App\Billing\WalletService;
use App\Http\Controllers\Api\Concerns\ResolvesMerchant;
use App\Http\Controllers\Controller;
use App\Models\CreditPack;
use App\Models\CreditTransaction;
use App\Models\PurchaseIntent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BillingController extends Controller
{
    use ResolvesMerchant;

    public function packs(): JsonResponse
    {
        return response()->json(['data' => CreditPack::query()->where('active', true)->orderBy('sort')->get(['id', 'salla_addon_slug', 'credits', 'name_ar', 'name_en'])]);
    }

    public function createIntent(Request $request, PurchaseService $purchases): JsonResponse
    {
        $data = $request->validate(['pack_id' => ['required', 'integer']]);
        $pack = CreditPack::query()->where('active', true)->whereKey((int) $data['pack_id'])->first();

        if ($pack === null) {
            abort(422, 'This credit pack is not available.');
        }

        $intent = $purchases->createIntent($this->merchant($request)->merchant_id, $pack);

        return response()->json(['data' => ['uuid' => $intent->uuid, 'credits' => $intent->credits, 'addon' => $pack->salla_addon_slug, 'expires_at' => $intent->expires_at]], 201);
    }

    public function intentResult(Request $request, string $uuid, PurchaseService $purchases): JsonResponse
    {
        $data = $request->validate(['status' => ['required', 'in:success,failed,pending'], 'order_id' => ['nullable', 'string', 'max:100']]);
        $intent = PurchaseIntent::query()->where('merchant_id', $this->merchant($request)->merchant_id)->where('uuid', $uuid)->firstOrFail();

        $intent = $purchases->recordResult($intent, $data['status'], $data['order_id'] ?? null);

        return response()->json(['data' => ['status' => $intent->status]]);
    }

    public function history(Request $request, WalletService $wallets): JsonResponse
    {
        $wallet = $wallets->walletFor($this->merchant($request)->merchant_id);
        $rows = CreditTransaction::query()->where('wallet_id', $wallet->id)->whereNotIn('type', [CreditTransaction::RESERVE, CreditTransaction::RELEASE])->latest('id')->paginate(25);

        return response()->json($rows->through(fn (CreditTransaction $t) => $t->only(['id', 'type', 'amount', 'balance_after', 'reason', 'created_at']))->toArray());
    }
}
