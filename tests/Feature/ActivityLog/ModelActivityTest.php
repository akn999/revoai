<?php

use App\Models\ActivityLog;
use App\Models\Merchant;
use App\Models\MerchantToken;
use App\Models\Subscription;
use App\Models\User;

test('creating, updating, deleting and restoring a model is logged with its subject', function () {
    $merchant = Merchant::factory()->create(['merchant_id' => 424242, 'name' => 'Old Name']);
    $merchant->update(['name' => 'New Name']);
    $merchant->delete();
    $merchant->restore();

    $entries = ActivityLog::where('channel', 'model')->orderBy('id')->get();

    expect($entries->pluck('action')->all())->toBe(['merchant.created', 'merchant.updated', 'merchant.deleted', 'merchant.restored'])
        ->and($entries->pluck('subject_id')->unique()->all())->toBe([(string) $merchant->id])
        ->and($entries->pluck('subject_type')->unique()->all())->toBe([Merchant::class])
        ->and($entries->pluck('merchant_id')->unique()->all())->toBe([424242])
        ->and($entries[0]->context['attributes']['name'])->toBe('Old Name')
        ->and($entries[1]->context['changes']['name'])->toBe(['old' => 'Old Name', 'new' => 'New Name'])
        ->and($entries[1]->message)->toBe("Merchant #{$merchant->id} updated");
});

test('a save without real changes logs nothing', function () {
    $merchant = Merchant::factory()->create();
    $before = ActivityLog::count();

    $merchant->save();
    $merchant->touch();

    expect(ActivityLog::count())->toBe($before);
});

test('hidden attributes are masked on create and update', function () {
    $merchant = Merchant::factory()->create();
    $token = MerchantToken::factory()->create(['merchant_id' => $merchant->merchant_id, 'access_token' => 'super-secret-access']);
    $token->update(['refresh_token' => 'super-secret-refresh']);

    $created = ActivityLog::where('action', 'merchant_token.created')->sole();
    $updated = ActivityLog::where('action', 'merchant_token.updated')->sole();

    expect($created->context['attributes']['access_token'])->toBe('[redacted]')
        ->and($updated->context['changes'])->toBe(['refresh_token' => '[redacted]'])
        ->and(json_encode(ActivityLog::all()->toArray()))->not->toContain('super-secret');
});

test('user password changes are logged without values', function () {
    $user = User::factory()->create();
    $user->update(['password' => 'a-brand-new-password']);

    expect(ActivityLog::where('action', 'user.updated')->sole()->context['changes'])->toBe(['password' => '[redacted]'])
        ->and(json_encode(ActivityLog::all()->toArray()))->not->toContain('a-brand-new-password');
});

test('domain rows carry their merchant so the trail can be filtered per merchant', function () {
    $merchant = Merchant::factory()->create();
    Subscription::factory()->create(['merchant_id' => $merchant->merchant_id]);

    expect(ActivityLog::where('action', 'subscription.created')->sole()->merchant_id)->toBe($merchant->merchant_id);
});

test('model logging can be switched off', function () {
    config(['activity-log.models.enabled' => false]);

    Merchant::factory()->create();

    expect(ActivityLog::count())->toBe(0);
});

test('activity logs never log themselves', function () {
    ActivityLog::factory()->create();

    expect(ActivityLog::where('channel', 'model')->count())->toBe(0);
});
