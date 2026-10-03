<?php

use App\Billing\WalletService;
use App\Enums\MerchantStatus;
use App\Jobs\FetchMerchantProfile;
use App\Models\ActivityLog;
use App\Models\AppFeedback;
use App\Models\CreditPack;
use App\Models\CreditTransaction;
use App\Models\EmbeddedSession;
use App\Models\Merchant;
use App\Models\MerchantSetting;
use App\Models\MerchantToken;
use App\Models\MerchantWallet;
use App\Models\Plan;
use App\Models\PurchaseIntent;
use App\Platform\EmbeddedSessionService;
use App\Sync\InitialProductSync;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Fixtures\RecordingHandler;

beforeEach(function () {
    config(['salla.webhook_secret' => SALLA_TEST_SECRET]);
    Http::preventStrayRequests();
    Queue::fake([FetchMerchantProfile::class, InitialProductSync::class]);
    $this->travelTo('2026-06-15 12:00:00');
    RecordingHandler::$handled = [];
});

function authorizeStore(int $minutes = 0, array $overrides = []): void
{
    deliverSalla(sallaEvent('app.store.authorize', authorizeData($overrides), $minutes))->assertOk();
}

function uninstallStore(int $minutes = 10): void
{
    deliverSalla(sallaEvent('app.uninstalled', ['uninstallation_date' => '2026-06-15 12:10:00'], $minutes))->assertOk();
}

describe('installation', function () {
    test('the first authorize creates the store, syncs, grants starter credits and defaults', function () {
        authorizeStore();

        $merchant = sallaMerchant();
        expect($merchant)
            ->status->toBe(MerchantStatus::Syncing)
            ->default_language->toBe('ar')
            ->enabled_languages->toBe(['ar', 'en'])
            ->reauth_required->toBeFalse()
            ->sync_started_at->not->toBeNull()
            ->and($merchant->token)->not->toBeNull()
            ->and(app(WalletService::class)->walletFor(SALLA_TEST_MERCHANT)->balance)->toBe(100);

        Queue::assertPushedOn('webhooks', InitialProductSync::class, fn ($job) => $job->merchantId === SALLA_TEST_MERCHANT && $job->fullResync === false);
        Queue::assertPushed(FetchMerchantProfile::class, 1);
    });

    test('app installed alone creates a pending store without credits', function () {
        deliverSalla(sallaEvent('app.installed', ['app_scopes' => []]))->assertOk();

        expect(sallaMerchant()->status)->toBe(MerchantStatus::Pending)
            ->and(MerchantWallet::count())->toBe(0);
    });

    test('authorizing again keeps an already synced store active and starts no second sync or grant', function () {
        authorizeStore(0);
        sallaMerchant()->forceFill(['status' => MerchantStatus::Active, 'sync_finished_at' => now()])->save();

        authorizeStore(5, ['access_token' => 'second-access']);

        expect(sallaMerchant())->status->toBe(MerchantStatus::Active)
            ->and(sallaMerchant()->token->access_token)->toBe('second-access')
            ->and(CreditTransaction::where('type', 'starter_grant')->count())->toBe(1);
        Queue::assertPushed(InitialProductSync::class, 1);
    });

    test('a store created by a subscription event is synced when its authorize arrives', function () {
        deliverSalla(sallaEvent('app.subscription.started', planData(), 0));
        expect(sallaMerchant()->status)->toBe(MerchantStatus::Active);

        authorizeStore(1);

        expect(sallaMerchant()->status)->toBe(MerchantStatus::Syncing);
        Queue::assertPushed(InitialProductSync::class, 1);
    });

    test('a stale authorize is ignored', function () {
        authorizeStore(10);
        uninstallStore(20);

        deliverSalla(sallaEvent('app.store.authorize', authorizeData(), 5))->assertOk();

        expect(sallaMerchant()->status)->toBe(MerchantStatus::Uninstalled);
    });
});

describe('uninstall and reinstall', function () {
    test('uninstalling starts the grace period, revokes sessions and releases reservations', function () {
        authorizeStore();
        ['token' => $token] = app(EmbeddedSessionService::class)->start(SALLA_TEST_MERCHANT, 1);
        $wallets = app(WalletService::class);
        $reservation = $wallets->reserve(SALLA_TEST_MERCHANT, 30, 'image_edit');
        expect($wallets->walletFor(SALLA_TEST_MERCHANT)->available())->toBe(70);

        uninstallStore();

        $merchant = sallaMerchant();
        expect($merchant)->status->toBe(MerchantStatus::Uninstalled)
            ->and($merchant->purge_at->toDateString())->toBe(now()->addDays(60)->toDateString())
            ->and($merchant->token)->toBeNull()
            ->and(app(EmbeddedSessionService::class)->authenticate($token))->toBeNull()
            ->and($reservation->fresh()->status)->toBe('released')
            ->and($wallets->walletFor(SALLA_TEST_MERCHANT)->available())->toBe(100);
    });

    test('store events other than app events are ignored while uninstalled', function () {
        config(['salla.handlers' => [...config('salla.handlers'), 'product.created' => RecordingHandler::class, 'app.installed' => RecordingHandler::class]]);
        authorizeStore();

        deliverSalla(sallaEvent('product.created', ['id' => 1], 1))->assertOk();
        expect(RecordingHandler::$handled)->toBe(['product.created']);

        uninstallStore(20);
        deliverSalla(sallaEvent('product.created', ['id' => 2], 30))->assertOk();
        deliverSalla(sallaEvent('app.installed', ['id' => 3], 40))->assertOk();

        expect(RecordingHandler::$handled)->toBe(['product.created', 'app.installed']);
    });

    test('reinstalling within the grace period restores the store and re-syncs everything', function () {
        authorizeStore(0);
        sallaMerchant()->forceFill(['status' => MerchantStatus::Active, 'sync_finished_at' => now()])->save();
        uninstallStore(10);
        $merchantId = sallaMerchant()->id;

        deliverSalla(sallaEvent('app.installed', ['app_scopes' => []], 20));
        expect(sallaMerchant()->status)->toBe(MerchantStatus::Pending);
        authorizeStore(30);

        $merchant = sallaMerchant();
        expect($merchant->id)->toBe($merchantId)
            ->and($merchant)->status->toBe(MerchantStatus::Syncing)->purge_at->toBeNull()->uninstalled_at->toBeNull()
            ->and(CreditTransaction::where('type', 'starter_grant')->count())->toBe(1)
            ->and(app(WalletService::class)->walletFor(SALLA_TEST_MERCHANT)->balance)->toBe(100);
        Queue::assertPushed(InitialProductSync::class, fn ($job) => $job->fullResync === true);
    });
});

describe('purge', function () {
    function seedTenantRows(): void
    {
        MerchantSetting::factory()->create(['merchant_id' => SALLA_TEST_MERCHANT]);
        AppFeedback::factory()->create(['merchant_id' => SALLA_TEST_MERCHANT]);
        app(EmbeddedSessionService::class)->start(SALLA_TEST_MERCHANT, 1);
    }

    test('nothing is purged before the grace period ends', function () {
        authorizeStore();
        seedTenantRows();
        uninstallStore();

        $this->travel(59)->days();
        $this->artisan('revo:stores:purge')->assertSuccessful();

        expect(sallaMerchant()->status)->toBe(MerchantStatus::Uninstalled)
            ->and(MerchantSetting::withoutGlobalScopes()->count())->toBe(1);
    });

    test('after the grace period tenant data goes but wallet, ledger and purchases stay', function () {
        authorizeStore();
        seedTenantRows();
        $pack = CreditPack::factory()->create();
        $intent = PurchaseIntent::factory()->create(['wallet_id' => app(WalletService::class)->walletFor(SALLA_TEST_MERCHANT)->id, 'pack_id' => $pack->id]);
        $transactions = CreditTransaction::count();
        sallaMerchant()->forceFill(['name' => 'Alpha', 'email' => 'owner@example.com'])->save();
        uninstallStore();

        $this->travel(61)->days();
        $this->artisan('revo:stores:purge')->assertSuccessful();

        $merchant = sallaMerchant();
        expect($merchant)->status->toBe(MerchantStatus::Purged)->name->toBeNull()->email->toBeNull()->purge_at->toBeNull()
            ->and(MerchantSetting::withoutGlobalScopes()->count())->toBe(0)
            ->and(AppFeedback::withoutGlobalScopes()->count())->toBe(0)
            ->and(MerchantToken::withoutGlobalScopes()->count())->toBe(0)
            ->and(EmbeddedSession::count())->toBe(0)
            ->and(CreditTransaction::count())->toBe($transactions)
            ->and(app(WalletService::class)->walletFor(SALLA_TEST_MERCHANT)->balance)->toBe(100)
            ->and(PurchaseIntent::whereKey($intent->id)->exists())->toBeTrue()
            ->and(ActivityLog::where('action', 'store.purged')->count())->toBe(1);
    });

    test('only the due store is purged and a second run changes nothing', function () {
        authorizeStore();
        seedTenantRows();
        uninstallStore();
        $other = Merchant::factory()->create(['status' => MerchantStatus::Uninstalled, 'purge_at' => now()->addDays(100)]);
        MerchantSetting::factory()->create(['merchant_id' => $other->merchant_id]);

        $this->travel(61)->days();
        $this->artisan('revo:stores:purge')->assertSuccessful();
        $this->artisan('revo:stores:purge')->assertSuccessful();

        expect(MerchantSetting::withoutGlobalScopes()->pluck('merchant_id')->all())->toBe([$other->merchant_id])
            ->and(ActivityLog::where('action', 'store.purged')->count())->toBe(1);
    });

    test('reinstalling after a purge is a fresh setup that keeps the wallet and grants nothing', function () {
        authorizeStore();
        app(WalletService::class)->credit(SALLA_TEST_MERCHANT, 400, CreditTransaction::PURCHASE);
        uninstallStore();
        $this->travel(61)->days();
        $this->artisan('revo:stores:purge')->assertSuccessful();

        deliverSalla(sallaEvent('app.installed', ['app_scopes' => []], 100000));
        authorizeStore(100001);

        expect(sallaMerchant()->status)->toBe(MerchantStatus::Syncing)
            ->and(app(WalletService::class)->walletFor(SALLA_TEST_MERCHANT)->balance)->toBe(500)
            ->and(CreditTransaction::where('type', 'starter_grant')->count())->toBe(1);
    });

    test('the purge schedule exists', function () {
        $this->artisan('schedule:list')->expectsOutputToContain('revo:stores:purge')->assertSuccessful();
    });
});

describe('plans', function () {
    beforeEach(function () {
        Plan::factory()->create(['slug' => 'plus', 'salla_plan_name' => 'Plus Plan', 'feature_flags' => ['product_content' => true, 'image_edit' => true, 'complaints' => false, 'chat' => false]]);
        Plan::factory()->create(['slug' => 'pro', 'salla_plan_name' => 'Pro Plan', 'feature_flags' => ['product_content' => true, 'image_edit' => true, 'complaints' => true, 'chat' => false]]);
    });

    test('a mapped subscription sets the Revo plan and its features', function () {
        deliverSalla(sallaEvent('app.subscription.started', planData(['plan_name' => 'Plus Plan']), 0));

        $merchant = sallaMerchant();
        expect($merchant)->plan_code->toBe('plus')->plan_status->toBe('active')->salla_plan_ref->toBe('Plus Plan')
            ->and($merchant->planAllows('product_content'))->toBeTrue()
            ->and($merchant->planAllows('complaints'))->toBeFalse()
            ->and($merchant->planAllows('unknown'))->toBeFalse();
    });

    test('a trial maps through the same table', function () {
        deliverSalla(sallaEvent('app.trial.started', ['plan_name' => 'Plus Plan', 'start_date' => '2026-06-10', 'end_date' => '2026-06-20', 'features' => []]));

        expect(sallaMerchant())->plan_code->toBe('plus')->plan_status->toBe('trial');
    });

    test('moving to another mapped plan switches the features', function () {
        deliverSalla(sallaEvent('app.subscription.started', planData(['plan_name' => 'Plus Plan']), 0));
        deliverSalla(sallaEvent('app.subscription.started', planData(['plan_name' => 'Pro Plan', 'subscription_id' => 2000000001]), 5));

        expect(sallaMerchant())->plan_code->toBe('pro')->and(sallaMerchant()->planAllows('complaints'))->toBeTrue();
    });

    test('an unmapped plan keeps the previous plan and raises an alert instead of locking the store', function () {
        deliverSalla(sallaEvent('app.subscription.started', planData(['plan_name' => 'Plus Plan']), 0));

        deliverSalla(sallaEvent('app.subscription.started', planData(['plan_name' => 'Mystery Plan', 'subscription_id' => 2000000001]), 5));

        $merchant = sallaMerchant();
        expect($merchant)->plan_code->toBe('plus')->plan_status->toBe('active')->salla_plan_ref->toBe('Mystery Plan')
            ->and($merchant->planAllows('product_content'))->toBeTrue()
            ->and(ActivityLog::where('action', 'plan.unmapped')->count())->toBe(1);
    });

    test('a lapsed subscription locks plan features but keeps the plan code, data and credits', function () {
        authorizeStore(0);
        deliverSalla(sallaEvent('app.subscription.started', planData(['plan_name' => 'Plus Plan']), 1));

        deliverSalla(sallaEvent('app.subscription.expired', planData(['plan_name' => 'Plus Plan']), 5));

        $merchant = sallaMerchant();
        expect($merchant)->plan_code->toBe('plus')->plan_status->toBe('lapsed')
            ->and($merchant->planAllows('product_content'))->toBeFalse()
            ->and($merchant->token)->not->toBeNull()
            ->and(app(WalletService::class)->walletFor(SALLA_TEST_MERCHANT)->balance)->toBe(100);
    });

    test('a canceled subscription keeps features until its end date', function () {
        deliverSalla(sallaEvent('app.subscription.started', planData(['plan_name' => 'Plus Plan']), 0));

        deliverSalla(sallaEvent('app.subscription.canceled', planData(['plan_name' => 'Plus Plan']), 5));

        expect(sallaMerchant()->planAllows('product_content'))->toBeTrue();
    });

    test('a store with no plan at all has no features', function () {
        deliverSalla(sallaEvent('app.installed', ['app_scopes' => []]));

        expect(sallaMerchant()->planAllows('product_content'))->toBeFalse();
    });
});

describe('webhook bookkeeping', function () {
    test('the last webhook time follows processed events only', function () {
        deliverSalla(sallaEvent('app.installed', ['app_scopes' => []], 0));
        $first = sallaMerchant()->last_webhook_at;

        $this->travel(10)->minutes();
        deliverSalla(sallaEvent('app.something.unknown', [], 1));
        expect(sallaMerchant()->last_webhook_at->equalTo($first))->toBeTrue();

        $this->travel(10)->minutes();
        deliverSalla(sallaEvent('app.updated', ['app_scopes' => []], 2));
        expect(sallaMerchant()->last_webhook_at->gt($first))->toBeTrue();
    });
});

describe('token refresh flag', function () {
    test('a failed refresh flags the store for re-authorization and a success clears it', function () {
        $merchant = Merchant::factory()->active()->create();
        $token = MerchantToken::factory()->expiring()->create(['merchant_id' => $merchant->merchant_id]);
        Http::fake(['accounts.salla.sa/oauth2/token' => Http::sequence()
            ->push(['error' => 'invalid_grant'], 400)
            ->push(['access_token' => 'a', 'refresh_token' => 'b', 'expires_in' => 1209600])]);

        $this->artisan('salla:tokens:refresh')->assertFailed();
        expect($merchant->fresh()->reauth_required)->toBeTrue();

        $this->artisan('salla:tokens:refresh')->assertSuccessful();
        expect($merchant->fresh()->reauth_required)->toBeFalse()
            ->and($token->fresh()->refreshed_at)->not->toBeNull();
    });

    test('authorize clears the flag', function () {
        authorizeStore();
        sallaMerchant()->forceFill(['reauth_required' => true])->save();

        authorizeStore(5);

        expect(sallaMerchant()->reauth_required)->toBeFalse();
    });

    test('tokens refresh within the configured window only', function () {
        $near = Merchant::factory()->active()->create();
        $far = Merchant::factory()->active()->create();
        MerchantToken::factory()->create(['merchant_id' => $near->merchant_id, 'expires_at' => now()->addDays(3)]);
        MerchantToken::factory()->create(['merchant_id' => $far->merchant_id, 'expires_at' => now()->addDays(4)]);
        Http::fake(['accounts.salla.sa/oauth2/token' => Http::response(['access_token' => 'a', 'refresh_token' => 'b', 'expires_in' => 1209600])]);

        $this->artisan('salla:tokens:refresh')->assertSuccessful();

        Http::assertSentCount(1);
    });
});
