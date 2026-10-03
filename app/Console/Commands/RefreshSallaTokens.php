<?php

namespace App\Console\Commands;

use App\Logging\Activity;
use App\Models\Merchant;
use App\Models\MerchantToken;
use App\Services\TokenService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

#[Signature('salla:tokens:refresh')]
#[Description('Refresh Salla access tokens that expire within three days')]
class RefreshSallaTokens extends Command
{
    public function handle(TokenService $tokens): int
    {
        $refreshed = 0;
        $failed = 0;

        MerchantToken::withoutGlobalScopes()
            ->whereNull('revoked_at')
            ->where('expires_at', '<=', now()->addDays((int) config('revo.limits.token_refresh_days')))
            ->each(function (MerchantToken $token) use ($tokens, &$refreshed, &$failed): void {
                try {
                    $tokens->refresh($token);
                    Merchant::query()->where('merchant_id', $token->merchant_id)->update(['reauth_required' => false]);
                    $refreshed++;
                } catch (Throwable $exception) {
                    $failed++;
                    Merchant::query()->where('merchant_id', $token->merchant_id)->update(['reauth_required' => true]);
                    Activity::channel('system')->bySystem()->forMerchant($token->merchant_id)->withException($exception)
                        ->error('salla.token_refresh_failed', 'Salla token refresh failed');

                    Log::error('Salla token refresh failed', [
                        'merchant_id' => $token->merchant_id,
                        'error' => $exception->getMessage(),
                    ]);
                }
            });

        $this->info("Refreshed {$refreshed} token(s), {$failed} failed.");

        Activity::channel('system')->bySystem()->with(compact('refreshed', 'failed'))
            ->log('salla.tokens_refresh_run', "Token refresh run: {$refreshed} refreshed, {$failed} failed", $failed > 0 ? 'warning' : 'info');

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
