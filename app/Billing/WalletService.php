<?php

namespace App\Billing;

use App\Billing\Exceptions\InsufficientCredits;
use App\Logging\Activity;
use App\Models\CreditReservation;
use App\Models\CreditTransaction;
use App\Models\MerchantWallet;
use App\Platform\RevoSettings;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Every balance change is an append-only ledger row written in the same transaction
 * as the wallet update, under a row lock, so concurrent jobs cannot overspend.
 */
class WalletService
{
    public function __construct(private RevoSettings $settings) {}

    public function walletFor(int $merchantId): MerchantWallet
    {
        return MerchantWallet::query()->firstOrCreate(['merchant_id' => $merchantId]);
    }

    /**
     * Grant the starter credits once per Salla merchant id, ever (FR-INS-002).
     */
    public function grantStarter(int $merchantId): ?CreditTransaction
    {
        $this->walletFor($merchantId);

        return DB::transaction(function () use ($merchantId): ?CreditTransaction {
            $wallet = $this->lock($merchantId);

            if ($wallet->starter_granted_at !== null) {
                return null;
            }

            $amount = $this->settings->starterCredits();
            $wallet->starter_granted_at = now();

            return $this->book($wallet, CreditTransaction::STARTER_GRANT, $amount, reason: 'Starter credits', actor: 'system');
        });
    }

    public function credit(int $merchantId, int $amount, string $type, ?string $reason = null, ?string $actor = null, ?string $referenceType = null, ?string $referenceId = null): CreditTransaction
    {
        if ($amount <= 0 || ! in_array($type, [CreditTransaction::PURCHASE, CreditTransaction::MANUAL_GRANT], true)) {
            throw new InvalidArgumentException('A credit must be a positive purchase or manual grant.');
        }

        $this->walletFor($merchantId);

        return DB::transaction(function () use ($merchantId, $amount, $type, $reason, $actor, $referenceType, $referenceId): CreditTransaction {
            $wallet = $this->lock($merchantId);

            return $this->book($wallet, $type, $amount, reason: $reason, actor: $actor, referenceType: $referenceType, referenceId: $referenceId);
        });
    }

    /**
     * Deduct credits manually. Never takes the balance below zero and never touches reserved credits.
     *
     * @return array{transaction: CreditTransaction|null, deducted: int, shortfall: int}
     */
    public function deduct(int $merchantId, int $amount, ?string $reason = null, ?string $actor = null, ?string $referenceType = null, ?string $referenceId = null): array
    {
        if ($amount <= 0) {
            throw new InvalidArgumentException('A deduction must be positive.');
        }

        $this->walletFor($merchantId);

        return DB::transaction(function () use ($merchantId, $amount, $reason, $actor, $referenceType, $referenceId): array {
            $wallet = $this->lock($merchantId);
            $deducted = min($amount, $wallet->available());

            $transaction = $deducted > 0
                ? $this->book($wallet, CreditTransaction::MANUAL_DEDUCT, -$deducted, reason: $reason, actor: $actor, referenceType: $referenceType, referenceId: $referenceId)
                : null;

            return ['transaction' => $transaction, 'deducted' => $deducted, 'shortfall' => $amount - $deducted];
        });
    }

    /**
     * Hold credits for a job. Refuses when the available balance is too low.
     */
    public function reserve(int $merchantId, int $amount, string $action, string $priceSource = 'default', ?string $referenceType = null, ?string $referenceId = null): CreditReservation
    {
        return $this->reserveMany($merchantId, [$amount], $action, $priceSource, $referenceType, [$referenceId])[0];
    }

    /**
     * Hold credits for several jobs at once. All are reserved or none: a bulk run only starts when the
     * balance covers the full estimate (FR-PRD-016).
     *
     * @param  array<int, int>  $amounts
     * @param  array<int, string|null>  $referenceIds  Same order as $amounts.
     * @return array<int, CreditReservation>
     */
    public function reserveMany(int $merchantId, array $amounts, string $action, string $priceSource = 'default', ?string $referenceType = null, array $referenceIds = []): array
    {
        foreach ($amounts as $amount) {
            if ($amount < 0) {
                throw new InvalidArgumentException('A reservation cannot be negative.');
            }
        }

        $this->walletFor($merchantId);

        return DB::transaction(function () use ($merchantId, $amounts, $action, $priceSource, $referenceType, $referenceIds): array {
            $wallet = $this->lock($merchantId);
            $total = array_sum($amounts);

            if ($wallet->available() < $total) {
                throw new InsufficientCredits($total, $wallet->available());
            }

            $reservations = [];

            foreach (array_values($amounts) as $index => $amount) {
                $reservation = CreditReservation::query()->create([
                    'wallet_id' => $wallet->id,
                    'merchant_id' => $merchantId,
                    'amount' => $amount,
                    'status' => CreditReservation::ACTIVE,
                    'action' => $action,
                    'price_source' => $priceSource,
                    'reference_type' => $referenceType,
                    'reference_id' => $referenceIds[$index] ?? null,
                ]);

                $wallet->reserved += $amount;
                $this->book($wallet, CreditTransaction::RESERVE, 0, reservedDelta: $amount, reservation: $reservation, referenceType: $referenceType, referenceId: $referenceIds[$index] ?? null);
                $reservations[] = $reservation;
            }

            return $reservations;
        });
    }

    /**
     * Turn a reservation into a charge. Idempotent: a resolved reservation is left alone.
     */
    public function capture(CreditReservation $reservation): bool
    {
        return $this->resolve($reservation, CreditReservation::CAPTURED, CreditTransaction::CAPTURE, null);
    }

    /**
     * Give a reservation back. Idempotent: a resolved reservation is left alone.
     */
    public function release(CreditReservation $reservation, ?string $reason = null): bool
    {
        return $this->resolve($reservation, CreditReservation::RELEASED, CreditTransaction::RELEASE, $reason);
    }

    /**
     * Release every active reservation older than the stale limit (killed workers).
     *
     * @return int Number of reservations released.
     */
    public function sweepStale(): int
    {
        $released = 0;

        CreditReservation::query()
            ->where('status', CreditReservation::ACTIVE)
            ->where('created_at', '<', now()->subMinutes((int) config('revo.limits.reservation_stale_minutes')))
            ->each(function (CreditReservation $reservation) use (&$released): void {
                if ($this->release($reservation, 'Released by the stale-reservation sweeper')) {
                    $released++;
                }
            });

        return $released;
    }

    /**
     * Release every active reservation of a merchant (uninstall, FR-INS-004).
     */
    public function releaseAllFor(int $merchantId, string $reason): int
    {
        $released = 0;

        CreditReservation::query()
            ->where('merchant_id', $merchantId)
            ->where('status', CreditReservation::ACTIVE)
            ->each(function (CreditReservation $reservation) use (&$released, $reason): void {
                if ($this->release($reservation, $reason)) {
                    $released++;
                }
            });

        return $released;
    }

    /**
     * True when the ledger sums match the stored wallet numbers (NFR-REL-002).
     */
    public function isConsistent(MerchantWallet $wallet): bool
    {
        $totals = CreditTransaction::query()
            ->where('wallet_id', $wallet->id)
            ->selectRaw('COALESCE(SUM(amount), 0) as balance, COALESCE(SUM(reserved_delta), 0) as reserved')
            ->first();

        $activeReserved = (int) CreditReservation::query()
            ->where('wallet_id', $wallet->id)
            ->where('status', CreditReservation::ACTIVE)
            ->sum('amount');

        return (int) $totals->balance === $wallet->balance
            && (int) $totals->reserved === $wallet->reserved
            && $activeReserved === $wallet->reserved;
    }

    private function resolve(CreditReservation $reservation, string $status, string $type, ?string $reason): bool
    {
        return DB::transaction(function () use ($reservation, $status, $type, $reason): bool {
            $fresh = CreditReservation::query()->lockForUpdate()->find($reservation->id);

            if (! $fresh || ! $fresh->isActive()) {
                return false;
            }

            $wallet = $this->lock($fresh->merchant_id);
            $wallet->reserved -= $fresh->amount;

            $fresh->forceFill(['status' => $status, 'resolved_at' => now()])->save();

            $this->book(
                $wallet,
                $type,
                $type === CreditTransaction::CAPTURE ? -$fresh->amount : 0,
                reservedDelta: -$fresh->amount,
                reservation: $fresh,
                reason: $reason,
                referenceType: $fresh->reference_type,
                referenceId: $fresh->reference_id,
            );

            $reservation->setRawAttributes($fresh->getAttributes(), true);

            return true;
        });
    }

    private function lock(int $merchantId): MerchantWallet
    {
        return MerchantWallet::query()->where('merchant_id', $merchantId)->lockForUpdate()->firstOrFail();
    }

    private function book(
        MerchantWallet $wallet,
        string $type,
        int $amount,
        int $reservedDelta = 0,
        ?CreditReservation $reservation = null,
        ?string $reason = null,
        ?string $actor = null,
        ?string $referenceType = null,
        ?string $referenceId = null,
    ): CreditTransaction {
        $wallet->balance += $amount;
        $wallet->save();

        $transaction = CreditTransaction::query()->create([
            'wallet_id' => $wallet->id,
            'merchant_id' => $wallet->merchant_id,
            'type' => $type,
            'amount' => $amount,
            'balance_after' => $wallet->balance,
            'reserved_delta' => $reservedDelta,
            'reserved_after' => $wallet->reserved,
            'reservation_id' => $reservation?->id,
            'reference_type' => $referenceType,
            'reference_id' => $referenceId,
            'reason' => $reason,
            'actor' => $actor,
        ]);

        Activity::channel('system')->bySystem()->forMerchant($wallet->merchant_id)->on($transaction)
            ->with(['type' => $type, 'amount' => $amount, 'balance_after' => $wallet->balance])
            ->info('credits.'.$type, "Credits {$type}: {$amount}");

        return $transaction;
    }
}
