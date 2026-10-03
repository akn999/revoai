<?php

namespace App\Billing;

use App\Billing\Verification\ClientResultVerifier;
use App\Billing\Verification\PurchaseVerifier;
use App\Billing\Verification\SallaConfirmedVerifier;
use App\Billing\Verification\VerificationOutcome;
use App\Logging\Activity;
use App\Models\CreditPack;
use App\Models\CreditTransaction;
use App\Models\PurchaseIntent;
use App\Platform\RevoSettings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class PurchaseService
{
    public function __construct(private WalletService $wallets, private RevoSettings $settings) {}

    public function verifier(): PurchaseVerifier
    {
        return match ($this->settings->purchaseVerifier()) {
            'salla_confirmed' => app(SallaConfirmedVerifier::class),
            default => app(ClientResultVerifier::class),
        };
    }

    /**
     * Start a purchase: single use, bound to the store and pack, valid for 24 hours.
     */
    public function createIntent(int $merchantId, CreditPack $pack): PurchaseIntent
    {
        if (! $pack->active) {
            throw new InvalidArgumentException('This credit pack is not available.');
        }

        $wallet = $this->wallets->walletFor($merchantId);

        return PurchaseIntent::query()->create([
            'uuid' => (string) Str::uuid(),
            'wallet_id' => $wallet->id,
            'merchant_id' => $merchantId,
            'pack_id' => $pack->id,
            'credits' => $pack->credits,
            'status' => PurchaseIntent::CREATED,
            'reversal_shortfall' => 0,
            'expires_at' => now()->addHours((int) config('revo.limits.intent_hours')),
        ]);
    }

    /**
     * Record a checkout result (FR-BIL-009). Credits are added only when the verifier confirms,
     * the intent is open, and the Salla order has never been credited before.
     */
    public function recordResult(PurchaseIntent $intent, string $status, ?string $orderId): PurchaseIntent
    {
        return DB::transaction(function () use ($intent, $status, $orderId): PurchaseIntent {
            $intent = PurchaseIntent::query()->lockForUpdate()->findOrFail($intent->id);

            if ($intent->status === PurchaseIntent::CONFIRMED) {
                return $intent;
            }

            if (! $intent->isOpen()) {
                if ($intent->status !== PurchaseIntent::EXPIRED && $intent->expires_at->isPast() && in_array($intent->status, [PurchaseIntent::CREATED, PurchaseIntent::PENDING], true)) {
                    $intent->forceFill(['status' => PurchaseIntent::EXPIRED])->save();
                }

                return $intent;
            }

            $verifier = $this->verifier();
            $outcome = $verifier->confirm($intent, $status, $orderId);
            $intent->verifier_strategy = $verifier->name();
            $intent->result = ['status' => $status, 'order_id' => $orderId];

            if ($outcome === VerificationOutcome::Confirmed) {
                $alreadyCredited = PurchaseIntent::query()
                    ->where('salla_order_id', $orderId)
                    ->where('id', '!=', $intent->id)
                    ->exists();

                if ($alreadyCredited) {
                    $intent->status = PurchaseIntent::FAILED;
                    $intent->save();

                    Activity::channel('system')->bySystem()->forMerchant($intent->merchant_id)->on($intent)
                        ->warning('purchase.duplicate_order', "Order {$orderId} was already credited");

                    return $intent;
                }

                $intent->forceFill(['status' => PurchaseIntent::CONFIRMED, 'salla_order_id' => $orderId])->save();
                $this->wallets->credit(
                    $intent->merchant_id,
                    $intent->credits,
                    CreditTransaction::PURCHASE,
                    reason: "Credit pack purchase (order {$orderId})",
                    actor: 'purchase',
                    referenceType: PurchaseIntent::class,
                    referenceId: (string) $intent->id,
                );

                return $intent;
            }

            $intent->status = $outcome === VerificationOutcome::Pending ? PurchaseIntent::PENDING : PurchaseIntent::FAILED;
            $intent->save();

            return $intent;
        });
    }

    public function markReconciled(PurchaseIntent $intent, string $actor): PurchaseIntent
    {
        $intent->forceFill(['reconciled_at' => now()])->save();

        Activity::channel('system')->bySystem()->forMerchant($intent->merchant_id)->on($intent)
            ->info('purchase.reconciled', "Purchase reconciled by {$actor}");

        return $intent;
    }

    /**
     * Reverse a confirmed purchase: deducts up to the available balance and records any shortfall.
     */
    public function reverse(PurchaseIntent $intent, string $actor): PurchaseIntent
    {
        if ($intent->status !== PurchaseIntent::CONFIRMED) {
            throw new InvalidArgumentException('Only a confirmed purchase can be reversed.');
        }

        return DB::transaction(function () use ($intent, $actor): PurchaseIntent {
            $result = $this->wallets->deduct(
                $intent->merchant_id,
                $intent->credits,
                reason: "Reversal of purchase #{$intent->id}",
                actor: $actor,
                referenceType: PurchaseIntent::class,
                referenceId: (string) $intent->id,
            );

            $intent->forceFill([
                'status' => PurchaseIntent::REVERSED,
                'reversed_at' => now(),
                'reversal_shortfall' => $result['shortfall'],
            ])->save();

            return $intent;
        });
    }
}
