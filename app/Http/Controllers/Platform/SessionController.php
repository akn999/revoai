<?php

namespace App\Http\Controllers\Platform;

use App\Enums\MerchantStatus;
use App\Http\Controllers\Controller;
use App\Logging\Activity;
use App\Models\Merchant;
use App\Platform\EmbeddedSessionService;
use App\Salla\SallaClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SessionController extends Controller
{
    /**
     * Exchange a Salla embedded token for the app's own session (FR-PLT-003, FR-PLT-004).
     */
    public function __invoke(Request $request, SallaClient $client, EmbeddedSessionService $sessions): JsonResponse
    {
        $validated = $request->validate(['token' => ['required', 'string', 'max:4096']]);

        $identity = $client->introspect($validated['token']);

        if ($identity === null) {
            Activity::channel('api')->bySystem()->warning('session.rejected', 'Salla rejected the embedded token');

            return response()->json(['message' => 'Invalid Salla token'], 401);
        }

        $merchant = Merchant::findBySallaId($identity['merchant_id']);

        if (! $merchant || in_array($merchant->status, [MerchantStatus::Uninstalled, MerchantStatus::Purged], true)) {
            return response()->json(['state' => 'not_installed', 'message' => 'The app is not installed for this store'], 404);
        }

        ['token' => $token, 'session' => $session] = $sessions->start($merchant->merchant_id, $identity['user_id'] ?? null);

        Activity::channel('api')->bySystem()->forMerchant($merchant->merchant_id)
            ->with(['salla_user_id' => $identity['user_id'] ?? null])
            ->info('session.started', 'Embedded session started');

        return response()->json([
            'token' => $token,
            'expires_at' => $session->expires_at->toIso8601String(),
            'state' => $merchant->token()->exists() ? 'ready' : 'awaiting_authorization',
        ]);
    }
}
