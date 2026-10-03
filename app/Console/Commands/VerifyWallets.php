<?php

namespace App\Console\Commands;

use App\Billing\WalletService;
use App\Logging\Activity;
use App\Models\MerchantWallet;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

#[Signature('revo:wallets:verify')]
#[Description('Check that every wallet balance equals its ledger sum and alert on any mismatch')]
class VerifyWallets extends Command
{
    public function handle(WalletService $wallets): int
    {
        $mismatches = [];

        MerchantWallet::query()->each(function (MerchantWallet $wallet) use ($wallets, &$mismatches): void {
            if (! $wallets->isConsistent($wallet)) {
                $mismatches[] = $wallet->merchant_id;

                Activity::channel('system')->bySystem()->forMerchant($wallet->merchant_id)->on($wallet)
                    ->critical('wallet.mismatch', 'Wallet balance does not equal its ledger');
                Log::critical('Wallet ledger mismatch', ['merchant_id' => $wallet->merchant_id]);
            }
        });

        $this->{$mismatches === [] ? 'info' : 'error'}($mismatches === []
            ? 'All wallets match their ledger.'
            : 'Mismatched wallets: '.implode(', ', $mismatches));

        return $mismatches === [] ? self::SUCCESS : self::FAILURE;
    }
}
