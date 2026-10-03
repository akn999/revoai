<?php

use App\Billing\PurchaseService;
use App\Billing\Verification\ClientResultVerifier;
use App\Billing\Verification\SallaConfirmedVerifier;
use App\Billing\Verification\VerificationOutcome;
use App\Billing\WalletService;
use App\Models\ActivityLog;
use App\Models\CreditPack;
use App\Models\CreditTransaction;
use App\Models\PurchaseIntent;
use App\Platform\RevoSettings;

beforeEach(function () {
    $this->travelTo('2026-06-15 12:00:00');
    $this->purchases = app(PurchaseService::class);
    $this->wallets = app(WalletService::class);
    $this->merchantId = 888001;
    $this->pack = CreditPack::factory()->create(['credits' => 500, 'salla_addon_slug' => 'credits_500']);
});

function balanceOf(int $merchantId): int
{
    return app(WalletService::class)->walletFor($merchantId)->balance;
}

describe('intents', function () {
    test('an intent is bound to the store and pack and expires after 24 hours', function () {
        $intent = $this->purchases->createIntent($this->merchantId, $this->pack);

        expect($intent)->merchant_id->toBe($this->merchantId)->pack_id->toBe($this->pack->id)->credits->toBe(500)->status->toBe('created')
            ->and($intent->uuid)->toBeString()
            ->and($intent->expires_at->equalTo(now()->addHours(24)))->toBeTrue()
            ->and($intent->isOpen())->toBeTrue();
    });

    test('an inactive pack cannot be bought', function () {
        $this->pack->update(['active' => false]);

        expect(fn () => $this->purchases->createIntent($this->merchantId, $this->pack))->toThrow(InvalidArgumentException::class);
    });

    test('the credits are copied so a later pack change does not alter an open intent', function () {
        $intent = $this->purchases->createIntent($this->merchantId, $this->pack);
        $this->pack->update(['credits' => 9999]);

        $this->purchases->recordResult($intent, 'paid', 'ORDER-1');

        expect(balanceOf($this->merchantId))->toBe(500);
    });
});

describe('results under the client_result strategy', function () {
    test('a paid result with an order id adds the credits once', function () {
        $intent = $this->purchases->createIntent($this->merchantId, $this->pack);

        $result = $this->purchases->recordResult($intent, 'paid', 'ORDER-1');

        $transaction = CreditTransaction::where('type', 'purchase')->sole();
        expect($result)->status->toBe('confirmed')->salla_order_id->toBe('ORDER-1')->verifier_strategy->toBe('client_result')
            ->and($result->reconciled_at)->toBeNull()
            ->and(balanceOf($this->merchantId))->toBe(500)
            ->and($transaction)->amount->toBe(500)->reference_id->toBe((string) $intent->id);
    });

    test('success counts as paid too', function () {
        $intent = $this->purchases->createIntent($this->merchantId, $this->pack);

        $this->purchases->recordResult($intent, 'success', 'ORDER-2');

        expect(balanceOf($this->merchantId))->toBe(500);
    });

    test('replaying the same order id on the same intent credits once', function () {
        $intent = $this->purchases->createIntent($this->merchantId, $this->pack);

        $this->purchases->recordResult($intent, 'paid', 'ORDER-1');
        $this->purchases->recordResult($intent, 'paid', 'ORDER-1');

        expect(balanceOf($this->merchantId))->toBe(500)->and(CreditTransaction::where('type', 'purchase')->count())->toBe(1);
    });

    test('an order id that was credited before is never credited again, even on a new intent', function () {
        $first = $this->purchases->createIntent($this->merchantId, $this->pack);
        $this->purchases->recordResult($first, 'paid', 'ORDER-1');
        $second = $this->purchases->createIntent($this->merchantId, $this->pack);

        $result = $this->purchases->recordResult($second, 'paid', 'ORDER-1');

        expect($result->status)->toBe('failed')->and(balanceOf($this->merchantId))->toBe(500)
            ->and(ActivityLog::where('action', 'purchase.duplicate_order')->count())->toBe(1);
    });

    test('a paid result without an order id adds nothing', function () {
        $intent = $this->purchases->createIntent($this->merchantId, $this->pack);

        $result = $this->purchases->recordResult($intent, 'paid', null);

        expect($result->status)->toBe('failed')->and(balanceOf($this->merchantId))->toBe(0);
    });

    test('pending keeps the intent open and adds nothing, and a later paid result completes it', function () {
        $intent = $this->purchases->createIntent($this->merchantId, $this->pack);

        $pending = $this->purchases->recordResult($intent, 'pending', 'ORDER-9');
        expect($pending->status)->toBe('pending')->and(balanceOf($this->merchantId))->toBe(0)->and($pending->isOpen())->toBeTrue();

        $paid = $this->purchases->recordResult($intent, 'paid', 'ORDER-9');
        expect($paid->status)->toBe('confirmed')->and(balanceOf($this->merchantId))->toBe(500);
    });

    test('failed and cancelled results add nothing', function (string $status) {
        $intent = $this->purchases->createIntent($this->merchantId, $this->pack);

        $result = $this->purchases->recordResult($intent, $status, 'ORDER-3');

        expect($result->status)->toBe('failed')->and(balanceOf($this->merchantId))->toBe(0);
    })->with(['failed', 'cancelled', 'whatever']);

    test('an expired intent adds nothing and is marked expired', function () {
        $intent = $this->purchases->createIntent($this->merchantId, $this->pack);

        $this->travel(25)->hours();
        $result = $this->purchases->recordResult($intent, 'paid', 'ORDER-1');

        expect($result->status)->toBe('expired')->and(balanceOf($this->merchantId))->toBe(0);
    });

    test('the intent expiry boundary is 24 hours', function () {
        $intent = $this->purchases->createIntent($this->merchantId, $this->pack);

        $this->travel(23)->hours();
        $this->purchases->recordResult($intent, 'paid', 'ORDER-1');

        expect(balanceOf($this->merchantId))->toBe(500);
    });

    test('a failed intent cannot be revived by a later paid result', function () {
        $intent = $this->purchases->createIntent($this->merchantId, $this->pack);
        $this->purchases->recordResult($intent, 'failed', 'ORDER-4');

        $this->purchases->recordResult($intent, 'paid', 'ORDER-4');

        expect(balanceOf($this->merchantId))->toBe(0);
    });

    test('credits go to the store the intent belongs to', function () {
        $mine = $this->purchases->createIntent($this->merchantId, $this->pack);
        $this->wallets->walletFor(888002);

        $this->purchases->recordResult($mine, 'paid', 'ORDER-5');

        expect(balanceOf($this->merchantId))->toBe(500)->and(balanceOf(888002))->toBe(0);
    });
});

describe('strategy switch', function () {
    test('client_result is the phase 1 default', function () {
        expect($this->purchases->verifier())->toBeInstanceOf(ClientResultVerifier::class);
    });

    test('switching to salla_confirmed changes behavior without a deploy and never confirms (placeholder)', function () {
        app(RevoSettings::class)->set('purchase_verifier', 'salla_confirmed');
        $intent = $this->purchases->createIntent($this->merchantId, $this->pack);

        expect($this->purchases->verifier())->toBeInstanceOf(SallaConfirmedVerifier::class);
        $result = $this->purchases->recordResult($intent, 'paid', 'ORDER-1');

        expect($result)->status->toBe('pending')->verifier_strategy->toBe('salla_confirmed')
            ->and(balanceOf($this->merchantId))->toBe(0);
    });

    test('the verifiers map statuses as documented', function (string $status, ?string $order, VerificationOutcome $expected) {
        $intent = PurchaseIntent::factory()->make();

        expect((new ClientResultVerifier)->confirm($intent, $status, $order))->toBe($expected);
    })->with([
        ['paid', 'O1', VerificationOutcome::Confirmed],
        ['PAID', 'O1', VerificationOutcome::Confirmed],
        ['success', 'O1', VerificationOutcome::Confirmed],
        ['paid', null, VerificationOutcome::Failed],
        ['paid', '', VerificationOutcome::Failed],
        ['pending', null, VerificationOutcome::Pending],
        ['failed', 'O1', VerificationOutcome::Failed],
        ['cancelled', 'O1', VerificationOutcome::Failed],
    ]);
});

describe('reconciliation and reversal', function () {
    test('a grant is flagged for reconciliation until an admin marks it reconciled', function () {
        $intent = $this->purchases->createIntent($this->merchantId, $this->pack);
        $this->purchases->recordResult($intent, 'paid', 'ORDER-1');
        expect($intent->fresh()->reconciled_at)->toBeNull();

        $this->purchases->markReconciled($intent->fresh(), 'admin:1');

        expect($intent->fresh()->reconciled_at)->not->toBeNull();
    });

    test('reversing a purchase deducts the credits and records the reversal', function () {
        $intent = $this->purchases->createIntent($this->merchantId, $this->pack);
        $this->purchases->recordResult($intent, 'paid', 'ORDER-1');

        $reversed = $this->purchases->reverse($intent->fresh(), 'admin:1');

        $deduction = CreditTransaction::where('type', 'manual_deduct')->sole();
        expect($reversed)->status->toBe('reversed')->reversal_shortfall->toBe(0)->reversed_at->not->toBeNull()
            ->and(balanceOf($this->merchantId))->toBe(0)
            ->and($deduction)->amount->toBe(-500)->actor->toBe('admin:1');
    });

    test('a reversal deducts up to the available balance and records any shortfall', function () {
        $intent = $this->purchases->createIntent($this->merchantId, $this->pack);
        $this->purchases->recordResult($intent, 'paid', 'ORDER-1');
        $this->wallets->deduct($this->merchantId, 350, 'spent');

        $reversed = $this->purchases->reverse($intent->fresh(), 'admin:1');

        expect($reversed->reversal_shortfall)->toBe(350)->and(balanceOf($this->merchantId))->toBe(0);
    });

    test('only a confirmed purchase can be reversed, and only once', function () {
        $intent = $this->purchases->createIntent($this->merchantId, $this->pack);

        expect(fn () => $this->purchases->reverse($intent, 'admin:1'))->toThrow(InvalidArgumentException::class);

        $this->purchases->recordResult($intent, 'paid', 'ORDER-1');
        $this->purchases->reverse($intent->fresh(), 'admin:1');

        expect(fn () => $this->purchases->reverse($intent->fresh(), 'admin:1'))->toThrow(InvalidArgumentException::class);
    });

    test('an order id of a reversed purchase can never be credited again', function () {
        $intent = $this->purchases->createIntent($this->merchantId, $this->pack);
        $this->purchases->recordResult($intent, 'paid', 'ORDER-1');
        $this->purchases->reverse($intent->fresh(), 'admin:1');
        $again = $this->purchases->createIntent($this->merchantId, $this->pack);

        $this->purchases->recordResult($again, 'paid', 'ORDER-1');

        expect(balanceOf($this->merchantId))->toBe(0);
    });
});

describe('maintenance', function () {
    test('the sweep expires unused intents but leaves confirmed ones', function () {
        $open = $this->purchases->createIntent($this->merchantId, $this->pack);
        $old = $this->purchases->createIntent($this->merchantId, $this->pack);
        $paid = $this->purchases->createIntent($this->merchantId, $this->pack);
        $this->purchases->recordResult($paid, 'paid', 'ORDER-7');
        PurchaseIntent::whereIn('id', [$old->id, $paid->id])->update(['expires_at' => now()->subHour()]);

        $this->artisan('revo:billing:sweep')->assertSuccessful();

        expect($old->fresh()->status)->toBe('expired')->and($open->fresh()->status)->toBe('created')->and($paid->fresh()->status)->toBe('confirmed');
    });
});
