<?php

use App\Enums\ActivityLevel;
use App\Enums\MerchantStatus;
use App\Logging\Activity;
use App\Models\ActivityLog;
use App\Models\Merchant;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Tests\Fixtures\RecursiveEnricher;
use Tests\Fixtures\StaticEnricher;

test('an entry stores channel, action, level, message and context', function () {
    $entry = Activity::info('user', 'profile.updated', 'Profile updated', ['field' => 'name']);

    expect($entry)->toBeInstanceOf(ActivityLog::class)
        ->and(ActivityLog::sole())
        ->channel->toBe('user')
        ->action->toBe('profile.updated')
        ->level->toBe(ActivityLevel::Info)
        ->message->toBe('Profile updated')
        ->context->toBe(['field' => 'name'])
        ->created_at->not->toBeNull();
});

test('every level has a shortcut', function (string $method, ActivityLevel $level) {
    Activity::$method('system', 'level.check');

    expect(ActivityLog::sole()->level)->toBe($level);
})->with([
    ['debug', ActivityLevel::Debug],
    ['info', ActivityLevel::Info],
    ['notice', ActivityLevel::Notice],
    ['warning', ActivityLevel::Warning],
    ['error', ActivityLevel::Error],
    ['critical', ActivityLevel::Critical],
]);

test('log accepts a level enum or a level name and falls back to info', function () {
    Activity::log(ActivityLevel::Alert, 'system', 'a');
    Activity::log('emergency', 'system', 'b');
    Activity::log('nonsense', 'system', 'c');

    expect(ActivityLog::orderBy('id')->pluck('level')->all())
        ->toBe([ActivityLevel::Alert, ActivityLevel::Emergency, ActivityLevel::Info]);
});

test('the fluent builder fills actor, subject, merchant, correlation and context', function () {
    $user = User::factory()->create();
    $merchant = Merchant::factory()->create();

    Activity::channel('api')
        ->by($user)
        ->on($merchant)
        ->forMerchant($merchant->merchant_id)
        ->correlatedWith('trace-123')
        ->with(['a' => 1])
        ->with(['b' => 2])
        ->http('POST', 'https://example.test/things', 201, 42)
        ->warning('thing.created', 'Created a thing');

    expect(ActivityLog::where('action', 'thing.created')->sole())
        ->channel->toBe('api')
        ->level->toBe(ActivityLevel::Warning)
        ->actor_type->toBe(User::class)
        ->actor_id->toBe((string) $user->id)
        ->subject_type->toBe(Merchant::class)
        ->subject_id->toBe((string) $merchant->id)
        ->merchant_id->toBe($merchant->merchant_id)
        ->correlation_id->toBe('trace-123')
        ->context->toBe(['a' => 1, 'b' => 2])
        ->http_method->toBe('POST')
        ->url->toBe('https://example.test/things')
        ->status_code->toBe(201)
        ->duration_ms->toBe(42);
});

test('withException stores class, message and a short trace', function () {
    Activity::channel('system')->withException(new RuntimeException('kaboom'))->error('job.failed');

    $exception = ActivityLog::sole()->context['exception'];
    expect($exception['class'])->toBe(RuntimeException::class)
        ->and($exception['message'])->toBe('kaboom')
        ->and($exception['trace'])->toBeArray()->not->toBeEmpty();
});

test('sensitive context keys are redacted at any depth and case', function () {
    Activity::info('user', 'form.submitted', null, [
        'name' => 'Ali',
        'password' => 'hunter2',
        'Authorization' => 'Bearer abc',
        'nested' => ['access_token' => 'tok', 'refresh_token' => 'tok2', 'keep' => 'me'],
        'headers' => ['X-Salla-Signature' => 'sig', 'Cookie' => 'a=b'],
        'client_secret' => 's',
    ]);

    expect(ActivityLog::sole()->context)->toBe([
        'name' => 'Ali',
        'password' => '[redacted]',
        'Authorization' => '[redacted]',
        'nested' => ['access_token' => '[redacted]', 'refresh_token' => '[redacted]', 'keep' => 'me'],
        'headers' => ['X-Salla-Signature' => '[redacted]', 'Cookie' => '[redacted]'],
        'client_secret' => '[redacted]',
    ]);
});

test('the redaction list is configurable', function () {
    config(['activity-log.redact' => ['nickname']]);

    Activity::info('user', 'x', null, ['nickname' => 'al', 'password' => 'visible']);

    expect(ActivityLog::sole()->context)->toBe(['nickname' => '[redacted]', 'password' => 'visible']);
});

test('context values are turned into JSON-safe data', function () {
    $merchant = Merchant::factory()->create();

    Activity::info('system', 'normalize', null, [
        'enum' => MerchantStatus::Active,
        'date' => CarbonImmutable::parse('2026-01-02 03:04:05', 'UTC'),
        'model' => $merchant,
        'stringable' => Str::of('text'),
        'object' => new stdClass,
        'inf' => INF,
        'bad_utf8' => "caf\xE9",
        'resource' => fopen('php://memory', 'r'),
        'null' => null,
        'bool' => false,
    ]);

    $context = ActivityLog::where('action', 'normalize')->sole()->context;
    expect($context['enum'])->toBe('active')
        ->and($context['date'])->toStartWith('2026-01-02T03:04:05')
        ->and($context['model'])->toBe(['_model' => Merchant::class, 'id' => $merchant->id])
        ->and($context['stringable'])->toBe('text')
        ->and($context['object'])->toBe('[stdClass]')
        ->and($context['inf'])->toBe('INF')
        ->and($context['bad_utf8'])->toBeString()
        ->and($context['resource'])->toBe('[resource (stream)]')
        ->and($context['null'])->toBeNull()
        ->and($context['bool'])->toBeFalse();
});

test('very deep context is cut off', function () {
    $deep = ['level' => 'end'];
    foreach (range(1, 10) as $i) {
        $deep = ['child' => $deep];
    }

    Activity::info('system', 'deep', null, $deep);

    expect(json_encode(ActivityLog::sole()->context))->toContain('[max depth]');
});

test('an oversized context is replaced by a truncated preview', function () {
    config(['activity-log.max_context_bytes' => 1000]);

    Activity::info('system', 'huge', null, ['blob' => str_repeat('x', 5000)]);

    $context = ActivityLog::sole()->context;
    expect($context['_truncated'])->toBeTrue()
        ->and($context['_original_bytes'])->toBeGreaterThan(5000)
        ->and(strlen($context['preview']))->toBeLessThanOrEqual(1000);
});

test('blank channel and action get defaults and long names are limited', function () {
    Activity::info('  ', '');
    Activity::info(str_repeat('c', 80), str_repeat('a', 200));

    $rows = ActivityLog::orderBy('id')->get();
    expect($rows[0])->channel->toBe('general')->action->toBe('unspecified')
        ->and(strlen($rows[1]->channel))->toBe(32)
        ->and(strlen($rows[1]->action))->toBe(96);
});

test('nothing is written while logging is disabled', function () {
    config(['activity-log.enabled' => false]);

    expect(Activity::info('system', 'ignored'))->toBeNull()
        ->and(ActivityLog::count())->toBe(0);
});

test('a failing write never throws and reports to the fallback channel', function () {
    config(['activity-log.table' => 'missing_table']);
    $channel = Mockery::mock();
    $channel->shouldReceive('error')->once()->withArgs(fn ($message) => str_contains($message, 'Activity log write failed'));
    Log::shouldReceive('channel')->once()->with('stderr')->andReturn($channel);

    expect(Activity::error('system', 'will.fail'))->toBeNull();
});

test('a broken fallback channel is swallowed too', function () {
    config(['activity-log.table' => 'missing_table']);
    Log::shouldReceive('channel')->andThrow(new RuntimeException('no logger'));

    expect(Activity::error('system', 'will.fail'))->toBeNull();
});

test('a write triggered while writing is skipped instead of recursing', function () {
    config(['activity-log.enrichers' => [RecursiveEnricher::class]]);

    Activity::info('system', 'outer');

    expect(ActivityLog::pluck('action')->all())->toBe(['outer']);
});

test('enrichers are configurable and never override explicit values', function () {
    config(['activity-log.enrichers' => [StaticEnricher::class]]);

    Activity::info('system', 'enriched');
    Activity::write(['channel' => 'system', 'action' => 'explicit', 'user_agent' => 'mine']);

    expect(ActivityLog::orderBy('id')->pluck('user_agent')->all())->toBe(['fixture-agent', 'mine']);
});

test('entries are append-only but can be deleted for retention', function () {
    $entry = Activity::info('system', 'immutable');

    expect(fn () => $entry->update(['action' => 'changed']))->toThrow(LogicException::class);

    $entry->delete();
    expect(ActivityLog::count())->toBe(0);
});

test('entries can be queried by channel, subject and merchant', function () {
    $merchant = Merchant::factory()->create();
    Activity::channel('webhook')->on($merchant)->forMerchant($merchant->merchant_id)->info('a');
    Activity::channel('user')->info('b');

    expect(ActivityLog::channel('webhook')->count())->toBe(1)
        ->and(ActivityLog::forSubject($merchant)->where('action', 'a')->count())->toBe(1)
        ->and(ActivityLog::forMerchant($merchant->merchant_id)->where('action', 'a')->count())->toBe(1);
});
