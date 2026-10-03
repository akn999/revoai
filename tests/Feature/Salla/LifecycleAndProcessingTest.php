<?php

use App\Enums\AppEventStatus;
use App\Enums\MerchantStatus;
use App\Enums\SubscriptionStatus;
use App\Jobs\FetchMerchantProfile;
use App\Jobs\ProcessAppEvent;
use App\Models\AppEvent;
use App\Models\AppFeedback;
use App\Models\Merchant;
use App\Models\MerchantSetting;
use App\Models\MerchantToken;
use App\Models\Subscription;
use App\Models\SubscriptionChange;
use App\Models\SubscriptionFeature;
use App\Models\SubscriptionPeriod;
use App\Support\CurrentMerchant;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Tests\Fixtures\ThrowingHandler;

test('EC-53 uninstall marks the merchant, deletes tokens and closes open subscriptions', function () {
    fakeUserInfo();
    deliverSalla(sallaEvent('app.store.authorize', authorizeData(), 0));
    deliverSalla(sallaEvent('app.subscription.started', planData(), 1));
    deliverSalla(sallaEvent('app.subscription.started', addonData(['subscription_id' => 3000000001]), 2));

    deliverSalla(sallaEvent('app.uninstalled', ['uninstallation_date' => '2026-06-15 12:46:07', 'refunded' => false], 5));

    $merchant = sallaMerchant();
    expect($merchant)->status->toBe(MerchantStatus::Uninstalled)
        ->uninstalled_at->toDateTimeString()->toBe('2026-06-15 12:46:07')
        ->and($merchant->canUseApp())->toBeFalse()
        ->and(MerchantToken::count())->toBe(0)
        ->and(Subscription::pluck('status')->unique()->all())->toBe([SubscriptionStatus::Canceled])
        ->and(Subscription::entitled()->count())->toBe(0)
        ->and(SubscriptionChange::where('change_type', 'canceled')->count())->toBe(2);
});

test('EC-54 reinstalling after an uninstall reuses the merchant row', function () {
    fakeUserInfo();
    deliverSalla(sallaEvent('app.store.authorize', authorizeData(), 0));
    $original = sallaMerchant();
    deliverSalla(sallaEvent('app.uninstalled', [], 5));

    deliverSalla(sallaEvent('app.store.authorize', authorizeData(['access_token' => 'new-access']), 10));

    $merchant = sallaMerchant();
    expect(Merchant::count())->toBe(1)
        ->and($merchant->id)->toBe($original->id)
        ->and($merchant->status)->toBe(MerchantStatus::Active)
        ->and($merchant->uninstalled_at)->toBeNull()
        ->and($merchant->token->access_token)->toBe('new-access');
});

test('an app installed event after an uninstall puts the merchant back to pending', function () {
    deliverSalla(sallaEvent('app.installed', ['app_scopes' => []], 0));
    deliverSalla(sallaEvent('app.uninstalled', [], 5));
    deliverSalla(sallaEvent('app.installed', ['app_scopes' => []], 10));

    expect(sallaMerchant()->status)->toBe(MerchantStatus::Pending);
});

test('EC-55 an uninstall older than the reinstall already processed is ignored', function () {
    fakeUserInfo();
    deliverSalla(sallaEvent('app.store.authorize', authorizeData(), 10));

    deliverSalla(sallaEvent('app.uninstalled', [], 5));

    expect(sallaMerchant()->status)->toBe(MerchantStatus::Active)
        ->and(MerchantToken::count())->toBe(1)
        ->and(AppEvent::where('event', 'app.uninstalled')->sole()->status)->toBe(AppEventStatus::Ignored);
});

test('a stale subscription event cannot reactivate an uninstalled merchant', function () {
    deliverSalla(sallaEvent('app.installed', ['app_scopes' => []], 0));
    deliverSalla(sallaEvent('app.uninstalled', [], 10));

    deliverSalla(sallaEvent('app.subscription.started', planData(), 5));

    expect(sallaMerchant()->status)->toBe(MerchantStatus::Uninstalled);
});

test('EC-56 settings delivered newest first keep the newest values', function () {
    deliverSalla(sallaEvent('app.settings.updated', ['settings' => ['name' => 'Newest']], 10));
    deliverSalla(sallaEvent('app.settings.updated', ['settings' => ['name' => 'Older']], 5));

    expect(MerchantSetting::sole()->settings)->toBe(['name' => 'Newest'])
        ->and(AppEvent::latest('id')->first()->status)->toBe(AppEventStatus::Ignored);

    deliverSalla(sallaEvent('app.settings.updated', ['settings' => ['name' => 'Latest', 'door_to_door' => true]], 15));

    expect(MerchantSetting::sole()->settings)->toBe(['name' => 'Latest', 'door_to_door' => true]);
});

test('EC-57 feedback with an Arabic name is stored and read back unchanged', function () {
    deliverSalla(sallaEvent('app.feedback.created', ['rating' => '5', 'rated_by' => 'الإخلاص-تعديل', 'comment' => 'ممتاز']));

    expect(AppFeedback::sole())
        ->rating->toBe(5)
        ->rated_by->toBe('الإخلاص-تعديل')
        ->comment->toBe('ممتاز')
        ->merchant_id->toBe(SALLA_TEST_MERCHANT);
});

test('EC-58 a handler that always throws is retried five times, then failed with the error saved', function () {
    Log::spy();
    config([
        'queue.default' => 'database',
        'salla.handlers' => ['app.boom' => ThrowingHandler::class],
    ]);

    deliverSalla(sallaEvent('app.boom'))->assertOk();
    expect(AppEvent::sole()->status)->toBe(AppEventStatus::Received);

    foreach (range(1, 7) as $attempt) {
        Artisan::call('queue:work', ['--once' => true, '--queue' => 'webhooks']);
    }

    $event = AppEvent::sole();
    expect($event)
        ->status->toBe(AppEventStatus::Failed)
        ->attempts->toBe(5)
        ->error->toBe('handler exploded');
    expect(DB::table('jobs')->count())->toBe(0)->and(DB::table('failed_jobs')->count())->toBe(1);
    Log::shouldHaveReceived('error')->withArgs(fn ($message) => $message === 'Salla app event failed')->once();
});

test('EC-59 replaying a failed event after the fix processes it once', function () {
    config(['queue.default' => 'database']);
    deliverSalla(sallaEvent('app.subscription.started', planData()));
    DB::table('jobs')->delete();
    $event = AppEvent::sole();
    $event->update(['status' => AppEventStatus::Failed, 'attempts' => 5, 'error' => 'boom']);
    config(['queue.default' => 'sync']);

    $this->artisan('salla:events:replay', ['id' => $event->id])->assertSuccessful();

    expect($event->refresh())->status->toBe(AppEventStatus::Processed)->error->toBeNull()->attempts->toBe(1)
        ->and(Subscription::count())->toBe(1)
        ->and(SubscriptionPeriod::count())->toBe(1);
});

test('replay with --failed reprocesses every failed event and nothing else', function () {
    deliverSalla(sallaEvent('app.subscription.started', planData(), 0));
    deliverSalla(sallaEvent('app.feedback.created', ['rating' => 4, 'rated_by' => 'A', 'comment' => 'ok'], 1));
    $feedbackEvent = AppEvent::where('event', 'app.feedback.created')->sole();
    $feedbackEvent->update(['status' => AppEventStatus::Failed, 'error' => 'boom']);
    AppFeedback::query()->delete();

    $this->artisan('salla:events:replay', ['--failed' => true])->assertSuccessful();

    expect($feedbackEvent->refresh()->status)->toBe(AppEventStatus::Processed)
        ->and(AppFeedback::count())->toBe(1)
        ->and(Subscription::count())->toBe(1);
});

test('replay needs an event id or --failed and reports when nothing matches', function () {
    $this->artisan('salla:events:replay')->assertFailed();
    $this->artisan('salla:events:replay', ['--failed' => true])->assertFailed();
    $this->artisan('salla:events:replay', ['id' => 9999])->assertFailed();
});

test('EC-60 replaying an already processed event creates no duplicates', function () {
    fakeUserInfo();
    deliverSalla(sallaEvent('app.store.authorize', authorizeData(), 0));
    deliverSalla(sallaEvent('app.subscription.started', planData(), 1));
    deliverSalla(sallaEvent('app.subscription.started', addonData(['subscription_id' => 3000000001]), 2));
    deliverSalla(sallaEvent('app.feedback.created', ['rating' => 4, 'rated_by' => 'A', 'comment' => 'ok'], 3));

    AppEvent::pluck('id')->each(fn ($id) => $this->artisan('salla:events:replay', ['id' => $id])->assertSuccessful());

    expect(Merchant::count())->toBe(1)
        ->and(MerchantToken::count())->toBe(1)
        ->and(Subscription::count())->toBe(2)
        ->and(SubscriptionPeriod::count())->toBe(2)
        ->and(SubscriptionFeature::count())->toBe(4)
        ->and(SubscriptionChange::count())->toBe(2)
        ->and(AppFeedback::count())->toBe(1)
        ->and(AppEvent::where('status', '!=', 'processed')->count())->toBe(0);
});

test('EC-61 queries with merchant A in context never return merchant B rows', function () {
    $a = Merchant::factory()->active()->create();
    $b = Merchant::factory()->active()->create();
    foreach ([$a, $b] as $merchant) {
        Subscription::factory()->create(['merchant_id' => $merchant->merchant_id]);
        MerchantToken::factory()->create(['merchant_id' => $merchant->merchant_id]);
        MerchantSetting::factory()->create(['merchant_id' => $merchant->merchant_id]);
    }

    app(CurrentMerchant::class)->set($a->merchant_id);

    expect(Subscription::pluck('merchant_id')->all())->toBe([$a->merchant_id])
        ->and(MerchantToken::pluck('merchant_id')->all())->toBe([$a->merchant_id])
        ->and(MerchantSetting::pluck('merchant_id')->all())->toBe([$a->merchant_id])
        ->and(Subscription::withoutGlobalScopes()->count())->toBe(2);

    $created = Subscription::factory()->addon()->make(['merchant_id' => null]);
    $created->save();
    expect($created->merchant_id)->toBe($a->merchant_id);
});

test('processing restores the previous merchant context afterwards', function () {
    app(CurrentMerchant::class)->set(77);

    deliverSalla(sallaEvent('app.installed', ['app_scopes' => []]));

    expect(app(CurrentMerchant::class)->id())->toBe(77);
});

test('processing one merchant is serialized by a per-merchant overlap lock', function () {
    $job = new ProcessAppEvent(1, SALLA_TEST_MERCHANT);

    expect($job->middleware()[0]->key)->toContain('salla-merchant:'.SALLA_TEST_MERCHANT);
});

test('a failed-event storm raises a critical alert', function () {
    Log::spy();
    config(['salla.failed_alert_threshold' => 1]);
    AppEvent::factory()->count(2)->create(['status' => AppEventStatus::Failed]);
    $event = AppEvent::factory()->create();

    (new ProcessAppEvent($event->id, $event->merchant_id))->failed(new RuntimeException('bad'));

    Log::shouldHaveReceived('critical')->once();
});

test('webhook events older than the retention are pruned', function () {
    $old = AppEvent::factory()->create(['created_at' => now()->subDays(91)]);
    $recent = AppEvent::factory()->create(['created_at' => now()->subDays(89)]);

    $this->artisan('model:prune', ['--model' => [AppEvent::class]])->assertSuccessful();

    expect(AppEvent::whereKey($old->id)->exists())->toBeFalse()
        ->and(AppEvent::whereKey($recent->id)->exists())->toBeTrue();
});

test('authorize event dispatches profile fetch only once per delivery', function () {
    Queue::fake([FetchMerchantProfile::class]);
    $body = sallaEvent('app.store.authorize', authorizeData());

    deliverSalla($body);
    deliverSalla($body);

    Queue::assertPushed(FetchMerchantProfile::class, 1);
});
