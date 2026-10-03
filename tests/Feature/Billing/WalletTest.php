<?php

use App\Billing\Exceptions\InsufficientCredits;
use App\Billing\WalletService;
use App\Models\ActivityLog;
use App\Models\CreditReservation;
use App\Models\CreditTransaction;
use App\Models\MerchantWallet;
use App\Platform\RevoSettings;

beforeEach(function () {
    $this->wallets = app(WalletService::class);
    $this->merchantId = 777001;
});

function fund(int $merchantId, int $amount): void
{
    app(WalletService::class)->credit($merchantId, $amount, CreditTransaction::MANUAL_GRANT, 'test funding');
}

describe('ledger', function () {
    test('starter credits are granted once per merchant id, ever', function () {
        $first = $this->wallets->grantStarter($this->merchantId);
        $second = $this->wallets->grantStarter($this->merchantId);

        $wallet = $this->wallets->walletFor($this->merchantId);
        expect($first->type)->toBe('starter_grant')->and($first->amount)->toBe(100)
            ->and($second)->toBeNull()
            ->and($wallet)->balance->toBe(100)->starter_granted_at->not->toBeNull()
            ->and(CreditTransaction::where('type', 'starter_grant')->count())->toBe(1);
    });

    test('the starter amount follows the admin setting for future installs', function () {
        app(RevoSettings::class)->set('starter_credits', 250);

        $this->wallets->grantStarter($this->merchantId);
        $this->wallets->grantStarter(777002);

        expect($this->wallets->walletFor($this->merchantId)->balance)->toBe(250);
    });

    test('every change writes an append-only row and the ledger always sums to the balance', function () {
        $this->wallets->grantStarter($this->merchantId);
        fund($this->merchantId, 50);
        $this->wallets->deduct($this->merchantId, 30, 'correction');
        $reservation = $this->wallets->reserve($this->merchantId, 40, 'image_edit');
        $this->wallets->capture($reservation);
        $second = $this->wallets->reserve($this->merchantId, 10, 'product_content');
        $this->wallets->release($second);

        $wallet = $this->wallets->walletFor($this->merchantId);
        expect($wallet)->balance->toBe(80)->reserved->toBe(0)
            ->and(CreditTransaction::where('wallet_id', $wallet->id)->sum('amount'))->toBe(80)
            ->and(CreditTransaction::where('wallet_id', $wallet->id)->sum('reserved_delta'))->toBe(0)
            ->and(CreditTransaction::where('wallet_id', $wallet->id)->orderBy('id')->pluck('type')->all())
            ->toBe(['starter_grant', 'manual_grant', 'manual_deduct', 'reserve', 'capture', 'reserve', 'release'])
            ->and($this->wallets->isConsistent($wallet))->toBeTrue();
    });

    test('balance_after follows each row', function () {
        $this->wallets->grantStarter($this->merchantId);
        fund($this->merchantId, 25);
        $this->wallets->deduct($this->merchantId, 5);

        expect(CreditTransaction::orderBy('id')->pluck('balance_after')->all())->toBe([100, 125, 120]);
    });

    test('ledger rows can never be updated or deleted', function () {
        $row = $this->wallets->grantStarter($this->merchantId);

        expect(fn () => $row->update(['amount' => 1]))->toThrow(LogicException::class)
            ->and(fn () => $row->delete())->toThrow(LogicException::class);
    });

    test('credits are written to the activity log', function () {
        fund($this->merchantId, 10);

        expect(ActivityLog::where('action', 'credits.manual_grant')->sole()->merchant_id)->toBe($this->merchantId);
    });

    test('invalid credit operations are refused', function () {
        expect(fn () => $this->wallets->credit($this->merchantId, 0, CreditTransaction::MANUAL_GRANT))->toThrow(InvalidArgumentException::class)
            ->and(fn () => $this->wallets->credit($this->merchantId, 5, CreditTransaction::CAPTURE))->toThrow(InvalidArgumentException::class)
            ->and(fn () => $this->wallets->deduct($this->merchantId, 0))->toThrow(InvalidArgumentException::class)
            ->and(fn () => $this->wallets->reserve($this->merchantId, -1, 'x'))->toThrow(InvalidArgumentException::class);
    });
});

describe('manual deductions', function () {
    test('a deduction never takes the balance below zero and reports the shortfall', function () {
        fund($this->merchantId, 30);

        $result = $this->wallets->deduct($this->merchantId, 50, 'chargeback');

        expect($result['deducted'])->toBe(30)->and($result['shortfall'])->toBe(20)
            ->and($this->wallets->walletFor($this->merchantId)->balance)->toBe(0);
    });

    test('a deduction never touches credits held by active reservations', function () {
        fund($this->merchantId, 100);
        $this->wallets->reserve($this->merchantId, 70, 'image_edit');

        $result = $this->wallets->deduct($this->merchantId, 60);

        expect($result['deducted'])->toBe(30)->and($result['shortfall'])->toBe(30)
            ->and($this->wallets->walletFor($this->merchantId))->balance->toBe(70)->reserved->toBe(70);
    });

    test('deducting from an empty wallet writes nothing', function () {
        $result = $this->wallets->deduct($this->merchantId, 10);

        expect($result['transaction'])->toBeNull()->and($result['shortfall'])->toBe(10)
            ->and(CreditTransaction::count())->toBe(0);
    });
});

describe('reservations', function () {
    test('available credits are the balance minus active reservations', function () {
        fund($this->merchantId, 100);

        $this->wallets->reserve($this->merchantId, 20, 'image_edit');
        $this->wallets->reserve($this->merchantId, 8, 'product_content');

        $wallet = $this->wallets->walletFor($this->merchantId);
        expect($wallet)->balance->toBe(100)->reserved->toBe(28)
            ->and($wallet->available())->toBe(72);
    });

    test('two 20-credit edits on 30 credits: exactly one starts', function () {
        fund($this->merchantId, 30);

        $first = $this->wallets->reserve($this->merchantId, 20, 'image_edit');

        expect(fn () => $this->wallets->reserve($this->merchantId, 20, 'image_edit'))
            ->toThrow(InsufficientCredits::class, '20 required, 10 available');
        expect($first->isActive())->toBeTrue()
            ->and(CreditReservation::count())->toBe(1)
            ->and($this->wallets->walletFor($this->merchantId)->available())->toBe(10);
    });

    test('a reservation of exactly the available amount succeeds and one more credit does not', function () {
        fund($this->merchantId, 20);

        expect(fn () => $this->wallets->reserve($this->merchantId, 21, 'image_edit'))->toThrow(InsufficientCredits::class);
        expect($this->wallets->reserve($this->merchantId, 20, 'image_edit')->amount)->toBe(20);
    });

    test('capturing charges the balance and frees the hold', function () {
        fund($this->merchantId, 100);
        $reservation = $this->wallets->reserve($this->merchantId, 20, 'image_edit', 'model', 'App\\Models\\ImageGeneration', '9');

        expect($this->wallets->capture($reservation))->toBeTrue();

        $wallet = $this->wallets->walletFor($this->merchantId);
        $capture = CreditTransaction::where('type', 'capture')->sole();
        expect($wallet)->balance->toBe(80)->reserved->toBe(0)
            ->and($reservation->fresh())->status->toBe('captured')->resolved_at->not->toBeNull()->price_source->toBe('model')
            ->and($capture)->amount->toBe(-20)->reservation_id->toBe($reservation->id)->reference_id->toBe('9');
    });

    test('releasing gives the credits back without charging', function () {
        fund($this->merchantId, 100);
        $reservation = $this->wallets->reserve($this->merchantId, 20, 'image_edit');

        expect($this->wallets->release($reservation, 'provider error'))->toBeTrue();

        $release = CreditTransaction::where('type', 'release')->sole();
        expect($this->wallets->walletFor($this->merchantId))->balance->toBe(100)->reserved->toBe(0)
            ->and($release)->amount->toBe(0)->reason->toBe('provider error')->reserved_delta->toBe(-20);
    });

    test('capture and release are idempotent: a resolved reservation is never resolved twice', function () {
        fund($this->merchantId, 100);
        $reservation = $this->wallets->reserve($this->merchantId, 20, 'image_edit');

        expect($this->wallets->capture($reservation))->toBeTrue()
            ->and($this->wallets->capture($reservation))->toBeFalse()
            ->and($this->wallets->release($reservation))->toBeFalse();

        expect($this->wallets->walletFor($this->merchantId))->balance->toBe(80)->reserved->toBe(0);
    });

    test('a zero-credit reservation works for uncharged actions', function () {
        $reservation = $this->wallets->reserve($this->merchantId, 0, 'product_context');

        expect($this->wallets->capture($reservation))->toBeTrue()
            ->and($this->wallets->walletFor($this->merchantId)->balance)->toBe(0);
    });

    test('reservations of one merchant never affect another', function () {
        fund($this->merchantId, 50);
        fund(777002, 50);

        $this->wallets->reserve($this->merchantId, 50, 'image_edit');

        expect($this->wallets->walletFor(777002)->available())->toBe(50)
            ->and(fn () => $this->wallets->reserve($this->merchantId, 1, 'image_edit'))->toThrow(InsufficientCredits::class);
    });
});

describe('sweeper', function () {
    test('stale reservations are released and fresh ones are kept', function () {
        fund($this->merchantId, 100);
        $stale = $this->wallets->reserve($this->merchantId, 20, 'image_edit');
        CreditReservation::whereKey($stale->id)->update(['created_at' => now()->subMinutes(61)]);
        $edge = $this->wallets->reserve($this->merchantId, 10, 'image_edit');
        CreditReservation::whereKey($edge->id)->update(['created_at' => now()->subMinutes(59)]);

        $this->artisan('revo:billing:sweep')->assertSuccessful();

        expect($stale->fresh()->status)->toBe('released')->and($edge->fresh()->status)->toBe('active')
            ->and($this->wallets->walletFor($this->merchantId))->reserved->toBe(10)->balance->toBe(100)
            ->and(CreditTransaction::where('type', 'release')->sole()->reason)->toContain('sweeper');
    });

    test('the sweeper is idempotent and scheduled', function () {
        fund($this->merchantId, 100);
        $stale = $this->wallets->reserve($this->merchantId, 20, 'image_edit');
        CreditReservation::whereKey($stale->id)->update(['created_at' => now()->subHours(2)]);

        $this->artisan('revo:billing:sweep')->assertSuccessful();
        $this->artisan('revo:billing:sweep')->assertSuccessful();

        expect(CreditTransaction::where('type', 'release')->count())->toBe(1);
        $this->artisan('schedule:list')->expectsOutputToContain('revo:billing:sweep')->expectsOutputToContain('revo:wallets:verify');
    });

    test('releasing everything of a merchant leaves others alone', function () {
        fund($this->merchantId, 100);
        fund(777002, 100);
        $this->wallets->reserve($this->merchantId, 20, 'image_edit');
        $this->wallets->reserve($this->merchantId, 8, 'product_content');
        $other = $this->wallets->reserve(777002, 20, 'image_edit');

        expect($this->wallets->releaseAllFor($this->merchantId, 'uninstalled'))->toBe(2)
            ->and($other->fresh()->status)->toBe('active')
            ->and($this->wallets->walletFor($this->merchantId)->reserved)->toBe(0);
    });
});

describe('consistency check', function () {
    test('a healthy ledger passes', function () {
        $this->wallets->grantStarter($this->merchantId);
        $this->wallets->reserve($this->merchantId, 20, 'image_edit');

        $this->artisan('revo:wallets:verify')->expectsOutputToContain('All wallets match')->assertSuccessful();
    });

    test('an injected mismatch raises a critical alert', function () {
        $this->wallets->grantStarter($this->merchantId);
        MerchantWallet::where('merchant_id', $this->merchantId)->update(['balance' => 999]);

        $this->artisan('revo:wallets:verify')->assertFailed();

        expect(ActivityLog::where('action', 'wallet.mismatch')->sole())->level->value->toBe('critical')->merchant_id->toBe($this->merchantId);
    });

    test('a reserved total that disagrees with the reservations is caught too', function () {
        fund($this->merchantId, 100);
        $this->wallets->reserve($this->merchantId, 20, 'image_edit');
        MerchantWallet::where('merchant_id', $this->merchantId)->update(['reserved' => 5]);

        $this->artisan('revo:wallets:verify')->assertFailed();
    });
});
