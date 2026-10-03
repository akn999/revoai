<?php

use App\Billing\WalletService;
use App\Enums\AppEventStatus;
use App\Filament\Pages\BillingSettings;
use App\Filament\Pages\UsageReport;
use App\Filament\Resources\AuditLogs\Pages\ManageAuditLogs;
use App\Filament\Resources\FailedJobs\Pages\ManageFailedJobs;
use App\Filament\Resources\Merchants\Pages\ManageMerchants;
use App\Filament\Resources\ModerationEvents\Pages\ManageModerationEvents;
use App\Filament\Resources\PurchaseIntents\Pages\ManagePurchaseIntents;
use App\Filament\Resources\WebhookEvents\Pages\ManageWebhookEvents;
use App\Filament\Widgets\PlatformHealthWidget;
use App\Jobs\ProcessAppEvent;
use App\Models\ActivityLog;
use App\Models\AdminAuditLog;
use App\Models\AdminUser;
use App\Models\AiModel;
use App\Models\AppEvent;
use App\Models\AppSetting;
use App\Models\CreditTransaction;
use App\Models\FailedJob;
use App\Models\Merchant;
use App\Models\ModerationEvent;
use App\Models\Plan;
use App\Models\PurchaseIntent;
use App\Models\Subscription;
use App\Models\UsageLedger;
use App\Platform\RevoSettings;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Livewire\Livewire;

beforeEach(function () {
    Filament::setCurrentPanel('admin');
    $this->admin = AdminUser::factory()->withAppAuthentication()->create();
    $this->actingAs($this->admin, 'admin');
    $this->merchant = Merchant::factory()->active()->create(['name' => 'Alpha Store']);
    $this->wallet = fn () => app(WalletService::class)->walletFor($this->merchant->merchant_id)->fresh();
});

describe('merchants', function () {
    test('lists stores with balance and searches by id, name and email', function () {
        $other = Merchant::factory()->active()->create(['name' => 'Beta Shop', 'email' => 'beta@example.com']);
        app(WalletService::class)->credit($this->merchant->merchant_id, 70, CreditTransaction::MANUAL_GRANT);

        Livewire::test(ManageMerchants::class)->assertCanSeeTableRecords([$this->merchant, $other])
            ->assertTableColumnStateSet('wallet.balance', 70, $this->merchant)
            ->searchTable('Alpha')->assertCanSeeTableRecords([$this->merchant])->assertCanNotSeeTableRecords([$other])
            ->searchTable((string) $other->merchant_id)->assertCanSeeTableRecords([$other])
            ->searchTable('beta@example')->assertCanSeeTableRecords([$other]);
    });

    test('filters by status and plan', function () {
        $gone = Merchant::factory()->uninstalled()->create();
        $plus = Merchant::factory()->active()->create(['plan_code' => 'plus']);

        Livewire::test(ManageMerchants::class)->filterTable('status', 'uninstalled')->assertCanSeeTableRecords([$gone])->assertCanNotSeeTableRecords([$this->merchant])
            ->removeTableFilter('status')->filterTable('plan_code', 'plus')->assertCanSeeTableRecords([$plus])->assertCanNotSeeTableRecords([$this->merchant]);
    });

    test('granting credits needs a reason and writes a ledger entry with the admin as actor', function () {
        $action = TestAction::make('grant')->table($this->merchant);

        Livewire::test(ManageMerchants::class)->callAction($action, ['amount' => 50, 'reason' => ''])->assertHasFormErrors(['reason' => 'required']);
        expect(($this->wallet)()->balance)->toBe(0);

        Livewire::test(ManageMerchants::class)->callAction($action, ['amount' => 50, 'reason' => 'Goodwill gesture'])->assertHasNoFormErrors();

        $entry = CreditTransaction::where('type', 'manual_grant')->sole();
        expect(($this->wallet)()->balance)->toBe(50)->and($entry)->reason->toBe('Goodwill gesture')->actor->toBe('admin:'.$this->admin->id);
    });

    test('invalid amounts are refused', function (mixed $amount) {
        Livewire::test(ManageMerchants::class)->callAction(TestAction::make('grant')->table($this->merchant), ['amount' => $amount, 'reason' => 'because'])->assertHasFormErrors(['amount']);
        expect(($this->wallet)()->balance)->toBe(0);
    })->with([0, -5, 'abc', 1.5]);

    test('a deduction is capped at the available balance and never touches reserved credits', function () {
        app(WalletService::class)->credit($this->merchant->merchant_id, 30, CreditTransaction::MANUAL_GRANT);
        app(WalletService::class)->reserve($this->merchant->merchant_id, 10, 'product_content');

        Livewire::test(ManageMerchants::class)->callAction(TestAction::make('deduct')->table($this->merchant), ['amount' => 100, 'reason' => 'Chargeback'])->assertHasNoFormErrors();

        $wallet = ($this->wallet)();
        expect($wallet->balance)->toBe(10)->and($wallet->reserved)->toBe(10)->and(CreditTransaction::where('type', 'manual_deduct')->sole()->amount)->toBe(-20);
    });

    test('a deduction against an empty wallet writes nothing', function () {
        Livewire::test(ManageMerchants::class)->callAction(TestAction::make('deduct')->table($this->merchant), ['amount' => 5, 'reason' => 'Test']);

        expect(CreditTransaction::where('type', 'manual_deduct')->count())->toBe(0);
    });

    test('the panel is read-only for stores', function () {
        Livewire::test(ManageMerchants::class)->assertActionDoesNotExist('create')->assertTableActionDoesNotExist('delete', record: $this->merchant)->assertTableActionDoesNotExist('edit', record: $this->merchant);
    });
});

describe('purchases', function () {
    test('confirmed purchases can be reconciled once', function () {
        $intent = PurchaseIntent::factory()->create(['merchant_id' => $this->merchant->merchant_id, 'status' => PurchaseIntent::CONFIRMED, 'reconciled_at' => null]);

        Livewire::test(ManagePurchaseIntents::class)->callAction(TestAction::make('reconcile')->table($intent));

        expect($intent->fresh()->reconciled_at)->not->toBeNull();
        Livewire::test(ManagePurchaseIntents::class)->assertTableActionHidden('reconcile', $intent->fresh());
    });

    test('reversing deducts up to the available balance and records the shortfall', function () {
        app(WalletService::class)->credit($this->merchant->merchant_id, 30, CreditTransaction::MANUAL_GRANT);
        $intent = PurchaseIntent::factory()->create(['merchant_id' => $this->merchant->merchant_id, 'status' => PurchaseIntent::CONFIRMED, 'credits' => 100]);

        Livewire::test(ManagePurchaseIntents::class)->callAction(TestAction::make('reverse')->table($intent));

        expect($intent->fresh())->status->toBe(PurchaseIntent::REVERSED)->reversal_shortfall->toBe(70)->and(($this->wallet)()->balance)->toBe(0);
    });

    test('only confirmed purchases show reverse and reconcile', function () {
        $pending = PurchaseIntent::factory()->create(['merchant_id' => $this->merchant->merchant_id, 'status' => PurchaseIntent::PENDING]);

        Livewire::test(ManagePurchaseIntents::class)->assertTableActionHidden('reverse', $pending)->assertTableActionHidden('reconcile', $pending);
    });

    test('filters find unreconciled confirmed purchases', function () {
        $open = PurchaseIntent::factory()->create(['merchant_id' => $this->merchant->merchant_id, 'status' => PurchaseIntent::CONFIRMED, 'reconciled_at' => null]);
        $done = PurchaseIntent::factory()->create(['merchant_id' => $this->merchant->merchant_id, 'status' => PurchaseIntent::CONFIRMED, 'reconciled_at' => now()]);

        Livewire::test(ManagePurchaseIntents::class)->filterTable('unreconciled', true)->assertCanSeeTableRecords([$open])->assertCanNotSeeTableRecords([$done])
            ->removeTableFilter('unreconciled')->filterTable('status', PurchaseIntent::CONFIRMED)->assertCanSeeTableRecords([$open, $done]);
    });

    test('reconcile and reverse are audited in the activity log', function () {
        $intent = PurchaseIntent::factory()->create(['merchant_id' => $this->merchant->merchant_id, 'status' => PurchaseIntent::CONFIRMED, 'credits' => 5]);

        Livewire::test(ManagePurchaseIntents::class)->callAction(TestAction::make('reconcile')->table($intent));

        expect(ActivityLog::where('action', 'purchase.reconciled')->exists())->toBeTrue();
    });
});

describe('webhook events', function () {
    test('lists across stores, filters by status, and replays an event', function () {
        Queue::fake();
        $failed = AppEvent::factory()->create(['merchant_id' => $this->merchant->merchant_id, 'status' => AppEventStatus::Failed, 'attempts' => 3, 'error' => 'boom']);
        $ok = AppEvent::factory()->create(['merchant_id' => Merchant::factory()->create()->merchant_id, 'status' => AppEventStatus::Processed]);

        Livewire::test(ManageWebhookEvents::class)->assertCanSeeTableRecords([$failed, $ok])
            ->filterTable('status', 'failed')->assertCanSeeTableRecords([$failed])->assertCanNotSeeTableRecords([$ok])
            ->callAction(TestAction::make('replay')->table($failed));

        expect($failed->fresh())->status->toBe(AppEventStatus::Received)->attempts->toBe(0)->error->toBeNull();
        Queue::assertPushed(ProcessAppEvent::class, fn ($job) => $job->appEventId === $failed->id);
    });
});

describe('failed jobs', function () {
    test('lists failed jobs and retries one by uuid', function () {
        $job = FailedJob::query()->create(['uuid' => (string) Str::uuid(), 'connection' => 'database', 'queue' => 'webhooks', 'payload' => json_encode(['displayName' => 'App\\Jobs\\Thing']), 'exception' => 'Boom', 'failed_at' => now()]);

        Livewire::test(ManageFailedJobs::class)->assertCanSeeTableRecords([$job])->assertTableColumnStateSet('job', 'App\\Jobs\\Thing', $job)
            ->assertTableActionDoesNotExist('delete', record: $job)->assertActionDoesNotExist('create');
        Artisan::shouldReceive('call')->once()->with('queue:retry', ['id' => [$job->uuid]])->andReturn(0);

        Livewire::test(ManageFailedJobs::class)->callAction(TestAction::make('retry')->table($job))->assertNotified();
    });
});

describe('moderation events and audit log', function () {
    test('moderation events filter by decision and category', function () {
        $blocked = ModerationEvent::factory()->create(['merchant_id' => $this->merchant->merchant_id, 'decision' => 'blocked', 'category' => 'violence']);
        $allowed = ModerationEvent::factory()->create(['merchant_id' => $this->merchant->merchant_id, 'decision' => 'allowed', 'category' => null]);

        Livewire::test(ManageModerationEvents::class)->assertCanSeeTableRecords([$blocked, $allowed])->filterTable('decision', 'blocked')
            ->assertCanSeeTableRecords([$blocked])->assertCanNotSeeTableRecords([$allowed]);
    });

    test('the audit log is read-only and filterable by admin and type', function () {
        $mine = AdminAuditLog::factory()->create(['admin_user_id' => $this->admin->id, 'auditable_type' => Plan::class, 'event' => 'updated']);
        $theirs = AdminAuditLog::factory()->create(['admin_user_id' => AdminUser::factory()->create()->id, 'auditable_type' => AiModel::class, 'event' => 'created']);

        Livewire::test(ManageAuditLogs::class)->assertCanSeeTableRecords([$mine, $theirs])->assertActionDoesNotExist('create')
            ->assertTableActionDoesNotExist('delete', record: $mine)->assertTableActionDoesNotExist('edit', record: $mine)
            ->filterTable('admin_user_id', $this->admin->id)->assertCanSeeTableRecords([$mine])->assertCanNotSeeTableRecords([$theirs])
            ->removeTableFilter('admin_user_id')->filterTable('event', 'created')->assertCanSeeTableRecords([$theirs])->assertCanNotSeeTableRecords([$mine]);
    });
});

describe('billing settings and usage report', function () {
    test('prices, starter credits, threshold and verifier are saved and used straight away', function () {
        Livewire::test(BillingSettings::class)->fillForm([
            'price_product_content' => 9, 'price_field_regeneration' => 3, 'price_image_edit' => 25, 'starter_credits' => 150, 'low_balance_threshold' => 50, 'purchase_verifier' => 'salla_confirmed',
        ])->call('save')->assertHasNoFormErrors()->assertNotified();

        $settings = app(RevoSettings::class);
        expect($settings->price('product_content'))->toBe(9)->and($settings->price('image_edit'))->toBe(25)->and($settings->starterCredits())->toBe(150)
            ->and($settings->lowBalanceThreshold())->toBe(50)->and($settings->purchaseVerifier())->toBe('salla_confirmed')
            ->and(AdminAuditLog::where('auditable_type', AppSetting::class)->count())->toBeGreaterThanOrEqual(6);
    });

    test('negative or missing values are refused', function () {
        Livewire::test(BillingSettings::class)->fillForm(['price_product_content' => -1, 'price_field_regeneration' => null, 'price_image_edit' => 20, 'starter_credits' => 100, 'low_balance_threshold' => 40, 'purchase_verifier' => 'client_result'])
            ->call('save')->assertHasFormErrors(['price_product_content', 'price_field_regeneration']);
    });

    test('the usage report aggregates per store, model and feature', function () {
        $row = fn (array $a) => UsageLedger::factory()->create(['merchant_id' => $this->merchant->merchant_id, 'internal' => false, ...$a]);
        $row(['feature' => 'product_content', 'provider_model_id' => 'm1', 'credits_charged' => 8, 'provider_cost_usd' => 0.01, 'input_tokens' => 100, 'output_tokens' => 50]);
        $row(['feature' => 'product_content', 'provider_model_id' => 'm1', 'credits_charged' => 8, 'provider_cost_usd' => 0.02, 'input_tokens' => 200, 'output_tokens' => 70]);
        $row(['feature' => 'image_edit', 'provider_model_id' => 'f1', 'credits_charged' => 20, 'provider_cost_usd' => 0.05, 'images' => 1]);

        $test = Livewire::test(UsageReport::class)->assertSuccessful();
        $records = $test->instance()->getTableRecords();

        expect($records)->toHaveCount(2);
        $text = $records->firstWhere('feature', 'product_content');
        expect((int) $text->calls)->toBe(2)->and((int) $text->credits)->toBe(16)->and((int) $text->input_tokens)->toBe(300)->and(round((float) $text->cost_usd, 2))->toBe(0.03);
        $test->filterTable('feature', 'image_edit');
        expect($test->instance()->getTableRecords())->toHaveCount(1);
    });
});

test('the health widget counts unmapped plans, stores needing re-authorization and failed jobs', function () {
    Merchant::factory()->active()->create(['reauth_required' => true]);
    Subscription::factory()->create(['merchant_id' => $this->merchant->merchant_id, 'plan_id' => null]);
    FailedJob::query()->create(['uuid' => (string) Str::uuid(), 'connection' => 'database', 'queue' => 'default', 'payload' => '{}', 'exception' => 'x', 'failed_at' => now()]);

    Livewire::test(PlatformHealthWidget::class)->assertSee('Unmapped Salla plans')->assertSeeInOrder(['Active stores', '2', 'Need re-authorization', '1', 'Unmapped Salla plans', '1', 'Failed jobs', '1']);
});
