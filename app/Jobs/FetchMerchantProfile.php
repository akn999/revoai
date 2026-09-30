<?php

namespace App\Jobs;

use App\Models\Merchant;
use App\Salla\SallaClient;
use App\Services\MerchantService;
use App\Services\TokenService;
use App\Support\CurrentMerchant;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use RuntimeException;

class FetchMerchantProfile implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [10, 60, 300];

    public function __construct(public int $merchantId) {}

    public function handle(
        SallaClient $client,
        MerchantService $merchants,
        TokenService $tokens,
        CurrentMerchant $context,
    ): void {
        $context->set($this->merchantId);

        $merchant = Merchant::findBySallaId($this->merchantId);
        $token = $merchant?->token;

        if (! $token || $token->revoked_at) {
            return;
        }

        if ($token->expires_at->isPast()) {
            $token = $tokens->refresh($token);
        }

        $info = $client->userInfo($token->access_token);
        $returnedMerchantId = data_get($info, 'merchant.id');

        if ((int) $returnedMerchantId !== $this->merchantId) {
            throw new RuntimeException("Salla user-info returned merchant [{$returnedMerchantId}] for merchant [{$this->merchantId}].");
        }

        $merchants->syncProfile($merchant, $info);
    }
}
