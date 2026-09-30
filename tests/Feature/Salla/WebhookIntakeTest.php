<?php

use App\Enums\AppEventStatus;
use App\Jobs\ProcessAppEvent;
use App\Models\AppEvent;
use App\Salla\PayloadVault;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

test('EC-01 request without a security strategy header is rejected', function () {
    $this->postJson('/api/webhooks/salla', sallaEvent('app.installed'))->assertUnauthorized();

    expect(AppEvent::count())->toBe(0);
});

test('EC-02 signature made with the wrong secret is rejected', function () {
    deliverSalla(sallaEvent('app.installed'), 'another-secret')->assertUnauthorized();

    expect(AppEvent::count())->toBe(0);
});

test('EC-03 signature is checked against the raw body', function () {
    $raw = json_encode(sallaEvent('app.installed'));
    $signature = hash_hmac('sha256', $raw, SALLA_TEST_SECRET);

    $this->call('POST', '/api/webhooks/salla', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_SALLA_SECURITY_STRATEGY' => 'Signature',
        'HTTP_X_SALLA_SIGNATURE' => $signature,
    ], $raw.' ')->assertUnauthorized();

    expect(AppEvent::count())->toBe(0);
});

test('token strategy accepts the bearer secret and rejects anything else', function () {
    $body = sallaEvent('app.installed');

    $this->postJson('/api/webhooks/salla', $body, [
        'X-Salla-Security-Strategy' => 'Token',
        'Authorization' => 'Bearer '.SALLA_TEST_SECRET,
    ])->assertOk();

    $this->postJson('/api/webhooks/salla', $body, [
        'X-Salla-Security-Strategy' => 'Token',
        'Authorization' => 'Bearer nope',
    ])->assertUnauthorized();
});

test('a missing webhook secret rejects every request', function () {
    config(['salla.webhook_secret' => null]);

    deliverSalla(sallaEvent('app.installed'), '')->assertUnauthorized();
});

test('EC-04 malformed bodies are rejected without storing or queueing', function (array|string $body) {
    Queue::fake();

    deliverSalla($body)->assertUnprocessable();

    expect(AppEvent::count())->toBe(0);
    Queue::assertNothingPushed();
})->with([
    'missing merchant' => [['event' => 'app.installed', 'data' => []]],
    'missing event' => [['merchant' => SALLA_TEST_MERCHANT, 'data' => []]],
    'not json' => ['this is not json'],
]);

test('a valid webhook is stored, acknowledged and processed', function () {
    deliverSalla(sallaEvent('app.installed', ['store_type' => 'demo']))
        ->assertOk()
        ->assertExactJson(['received' => true]);

    expect(lastEvent())
        ->status->toBe(AppEventStatus::Processed)
        ->merchant_id->toBe(SALLA_TEST_MERCHANT)
        ->event->toBe('app.installed');
});

test('EC-05 the same delivery twice is stored once and queued once', function () {
    Queue::fake();
    $body = sallaEvent('app.installed');

    deliverSalla($body)->assertOk();
    deliverSalla($body)->assertOk();

    expect(AppEvent::count())->toBe(1);
    Queue::assertPushed(ProcessAppEvent::class, 1);
});

test('EC-06 the database refuses a second row for the same payload hash', function () {
    AppEvent::factory()->create(['payload_hash' => str_repeat('a', 64)]);

    expect(fn () => AppEvent::factory()->create(['payload_hash' => str_repeat('a', 64)]))
        ->toThrow(UniqueConstraintViolationException::class);
});

test('EC-07 an unknown event name is kept and marked ignored', function () {
    deliverSalla(sallaEvent('app.something.new'))->assertOk();

    expect(lastEvent())->status->toBe(AppEventStatus::Ignored)->error->toBeNull();
});

test('EC-08 a payload without created_at falls back to the received time', function () {
    $body = sallaEvent('app.installed');
    unset($body['created_at']);

    deliverSalla($body)->assertOk();

    expect(lastEvent()->event_created_at->equalTo(now()))->toBeTrue();
});

test('EC-09 tokens are never stored in plain text', function () {
    fakeUserInfo();

    deliverSalla(sallaEvent('app.store.authorize', authorizeData()))->assertOk();

    $stored = DB::table('app_events')->value('payload');

    expect($stored)->not->toContain('plain-access-token')->not->toContain('plain-refresh-token');
    expect(app(PayloadVault::class)->reveal(lastEvent()->data()))
        ->access_token->toBe('plain-access-token')
        ->refresh_token->toBe('plain-refresh-token');
});
