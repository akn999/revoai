<?php

namespace App\Console\Commands;

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
            ->where('expires_at', '<=', now()->addDays(3))
            ->each(function (MerchantToken $token) use ($tokens, &$refreshed, &$failed): void {
                try {
                    $tokens->refresh($token);
                    $refreshed++;
                } catch (Throwable $exception) {
                    $failed++;
                    Log::error('Salla token refresh failed', [
                        'merchant_id' => $token->merchant_id,
                        'error' => $exception->getMessage(),
                    ]);
                }
            });

        $this->info("Refreshed {$refreshed} token(s), {$failed} failed.");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
