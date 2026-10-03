<?php

use App\Enums\MerchantStatus;
use App\Http\Middleware\AuthenticateEmbeddedSession;
use App\Models\AdminUser;
use App\Models\AppEvent;
use App\Models\AppSetting;
use App\Models\EmbeddedSession;
use App\Models\Merchant;
use App\Models\MerchantToken;
use App\Models\User;
use App\Platform\EmbeddedSessionService;
use App\Platform\RevoSettings;
use App\Platform\SallaDate;
use App\Support\CurrentMerchant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    config(['salla.app_id' => '1234']);
    Http::preventStrayRequests();
});

function introspectAs(int $merchantId, int $userId = 55): void
{
    Http::fake(['api.salla.dev/*' => Http::response(['status' => 200, 'success' => true, 'data' => ['merchant_id' => $merchantId, 'user_id' => $userId]])]);
}

describe('settings', function () {
    test('config values are the defaults', function () {
        $settings = app(RevoSettings::class);

        expect($settings->price('product_content'))->toBe(8)
            ->and($settings->price('field_regeneration'))->toBe(2)
            ->and($settings->price('image_edit'))->toBe(20)
            ->and($settings->starterCredits())->toBe(100)
            ->and($settings->lowBalanceThreshold())->toBe(40)
            ->and($settings->purchaseVerifier())->toBe('client_result');
    });

    test('an admin-edited value overrides config immediately and can be removed again', function () {
        $settings = app(RevoSettings::class);

        $settings->set('prices.image_edit', 25);
        expect($settings->price('image_edit'))->toBe(25)
            ->and(AppSetting::find('prices.image_edit')->value)->toBe(25);

        $settings->forget('prices.image_edit');
        expect($settings->price('image_edit'))->toBe(20);
    });
});

describe('Salla dates', function () {
    test('both documented formats parse to the same instant', function () {
        $withOffset = SallaDate::parse('Mon Apr 17 2023 12:14:21 GMT+0300');
        $riyadh = SallaDate::parse('2023-04-17 12:14:21');

        expect($withOffset->equalTo($riyadh))->toBeTrue()
            ->and($withOffset->toDateTimeString())->toBe('2023-04-17 09:14:21');
    });

    test('a timestamp without an offset is read as Asia/Riyadh', function () {
        expect(SallaDate::parse('2026-10-02 10:43:00')->toDateTimeString())->toBe('2026-10-02 07:43:00');
    });

    test('an ISO timestamp with an offset keeps it', function () {
        expect(SallaDate::parse('2026-10-02T10:43:00+00:00')->toDateTimeString())->toBe('2026-10-02 10:43:00');
    });

    test('garbage and blanks are null', function (mixed $value) {
        expect(SallaDate::parse($value))->toBeNull();
    })->with(['not a date', '', null, 123]);
});

describe('embedded sessions', function () {
    test('a started session authenticates and stores only a hash', function () {
        ['token' => $token, 'session' => $session] = app(EmbeddedSessionService::class)->start(500, 7);

        expect($session->token_hash)->not->toBe($token)->and(strlen($token))->toBe(64)
            ->and(app(EmbeddedSessionService::class)->authenticate($token)?->merchant_id)->toBe(500);
    });

    test('unknown, revoked, idle and expired sessions are rejected', function () {
        $service = app(EmbeddedSessionService::class);
        ['token' => $revoked] = $service->start(500, 7);
        $service->revokeForMerchant(500);
        ['token' => $idle] = $service->start(501, 7);
        EmbeddedSession::where('merchant_id', 501)->update(['last_used_at' => now()->subMinutes(61)]);
        ['token' => $expired] = $service->start(502, 7);
        EmbeddedSession::where('merchant_id', 502)->update(['expires_at' => now()->subSecond()]);

        expect($service->authenticate('nope'))->toBeNull()
            ->and($service->authenticate(null))->toBeNull()
            ->and($service->authenticate($revoked))->toBeNull()
            ->and($service->authenticate($idle))->toBeNull()
            ->and($service->authenticate($expired))->toBeNull();
    });

    test('use refreshes the idle timer but never the absolute limit', function () {
        $service = app(EmbeddedSessionService::class);
        ['token' => $token, 'session' => $session] = $service->start(500, 7);
        $originalExpiry = $session->expires_at->toIso8601String();

        $this->travel(59)->minutes();
        expect($service->authenticate($token))->not->toBeNull();
        $this->travel(59)->minutes();
        expect($service->authenticate($token))->not->toBeNull()
            ->and($session->fresh()->expires_at->toIso8601String())->toBe($originalExpiry);

        $this->travelTo(now()->addHours(8));
        expect($service->authenticate($token))->toBeNull();
    });

    test('revoking one merchant leaves other merchants alone', function () {
        $service = app(EmbeddedSessionService::class);
        ['token' => $a] = $service->start(500, 1);
        ['token' => $b] = $service->start(501, 1);

        expect($service->revokeForMerchant(500))->toBe(1)
            ->and($service->authenticate($a))->toBeNull()
            ->and($service->authenticate($b))->not->toBeNull();
    });

    test('pruning removes only old dead sessions', function () {
        $service = app(EmbeddedSessionService::class);
        $service->start(1, 1);
        $dead = EmbeddedSession::factory()->create(['expires_at' => now()->subDays(3), 'updated_at' => now()->subDays(3)]);
        $recentlyDead = EmbeddedSession::factory()->create(['expires_at' => now()->subHour(), 'updated_at' => now()->subHour()]);

        expect($service->pruneExpired())->toBe(1)
            ->and(EmbeddedSession::whereKey($dead->id)->exists())->toBeFalse()
            ->and(EmbeddedSession::whereKey($recentlyDead->id)->exists())->toBeTrue()
            ->and(EmbeddedSession::count())->toBe(2);
    });
});

describe('session endpoint', function () {
    test('a valid Salla token starts a session for an installed store', function () {
        $merchant = Merchant::factory()->active()->create();
        MerchantToken::factory()->create(['merchant_id' => $merchant->merchant_id]);
        introspectAs($merchant->merchant_id, 91);

        $response = $this->postJson('/api/app/session', ['token' => 'em_tok_abc'])->assertOk()->assertJsonPath('state', 'ready');

        expect(app(EmbeddedSessionService::class)->authenticate($response->json('token'))->salla_user_id)->toBe(91);
        Http::assertSent(fn ($request) => $request->hasHeader('S-Source', '1234') && $request['token'] === 'em_tok_abc');
    });

    test('a store without OAuth tokens yet reports awaiting authorization', function () {
        $merchant = Merchant::factory()->create(['status' => MerchantStatus::Pending]);
        introspectAs($merchant->merchant_id);

        $this->postJson('/api/app/session', ['token' => 'x'])->assertOk()->assertJsonPath('state', 'awaiting_authorization');
    });

    test('Salla rejecting the token returns 401 and creates no session', function () {
        Http::fake(['api.salla.dev/*' => Http::response(['success' => false], 401)]);

        $this->postJson('/api/app/session', ['token' => 'forged'])->assertUnauthorized();

        expect(EmbeddedSession::count())->toBe(0);
    });

    test('an unknown, uninstalled or purged store gets no session', function (?MerchantStatus $status) {
        $merchantId = 424242;
        $status && Merchant::factory()->create(['merchant_id' => $merchantId, 'status' => $status]);
        introspectAs($merchantId);

        $this->postJson('/api/app/session', ['token' => 'x'])->assertNotFound()->assertJsonPath('state', 'not_installed');

        expect(EmbeddedSession::count())->toBe(0);
    })->with([[null], [MerchantStatus::Uninstalled], [MerchantStatus::Purged]]);

    test('the token is required', function () {
        $this->postJson('/api/app/session', [])->assertUnprocessable();
        Http::assertNothingSent();
    });
});

describe('session middleware', function () {
    beforeEach(function () {
        Route::middleware(['api', AuthenticateEmbeddedSession::class])->get('/api/app/_whoami', fn (Request $request) => [
            'merchant_id' => $request->attributes->get('salla_merchant_id'),
            'user_id' => $request->attributes->get('salla_user_id'),
            'current' => app(CurrentMerchant::class)->id(),
        ]);
    });

    test('a valid session binds the request to its merchant', function () {
        $merchant = Merchant::factory()->active()->create();
        ['token' => $token] = app(EmbeddedSessionService::class)->start($merchant->merchant_id, 12);

        $this->getJson('/api/app/_whoami', ['Authorization' => 'Bearer '.$token])
            ->assertOk()
            ->assertExactJson(['merchant_id' => $merchant->merchant_id, 'user_id' => 12, 'current' => $merchant->merchant_id]);
    });

    test('missing or wrong tokens are 401', function () {
        $this->getJson('/api/app/_whoami')->assertUnauthorized();
        $this->getJson('/api/app/_whoami', ['Authorization' => 'Bearer wrong'])->assertUnauthorized();
    });

    test('a session of an uninstalled store stops working', function () {
        $merchant = Merchant::factory()->active()->create();
        ['token' => $token] = app(EmbeddedSessionService::class)->start($merchant->merchant_id, 12);
        $merchant->update(['status' => MerchantStatus::Uninstalled]);

        $this->getJson('/api/app/_whoami', ['Authorization' => 'Bearer '.$token])->assertUnauthorized();
    });

    test('session A can never act as store B', function () {
        $a = Merchant::factory()->active()->create();
        $b = Merchant::factory()->active()->create();
        ['token' => $tokenA] = app(EmbeddedSessionService::class)->start($a->merchant_id, 1);

        $this->getJson('/api/app/_whoami?merchant_id='.$b->merchant_id, ['Authorization' => 'Bearer '.$tokenA, 'X-Merchant-Id' => (string) $b->merchant_id])
            ->assertJsonPath('merchant_id', $a->merchant_id);
    });

    test('merchant APIs are rate limited per session', function () {
        config(['revo.limits.api_requests_per_minute' => 3]);
        Route::middleware(['api', AuthenticateEmbeddedSession::class, 'throttle:app-api'])->get('/api/app/_limited', fn () => 'ok');
        $merchant = Merchant::factory()->active()->create();
        ['token' => $token] = app(EmbeddedSessionService::class)->start($merchant->merchant_id, 1);
        ['token' => $other] = app(EmbeddedSessionService::class)->start($merchant->merchant_id, 2);

        foreach (range(1, 3) as $i) {
            $this->getJson('/api/app/_limited', ['Authorization' => 'Bearer '.$token])->assertOk();
        }
        $this->getJson('/api/app/_limited', ['Authorization' => 'Bearer '.$token])->assertStatus(429);
        $this->getJson('/api/app/_limited', ['Authorization' => 'Bearer '.$other])->assertOk();
    });
});

describe('security headers and outbound allow-list', function () {
    test('the embedded page can only be framed by Salla', function () {
        $this->get('/embedded')->assertOk()->assertHeader('Content-Security-Policy', 'frame-ancestors https://s.salla.sa');
    });

    test('requests to hosts outside the allow-list are refused before they are sent', function () {
        Http::fake();

        expect(fn () => Http::get('https://evil.example.org/steal'))->toThrow(RuntimeException::class, 'not allowed');
        Http::assertNothingSent();
    });

    test('allowed hosts pass, including wildcard entries', function (string $url) {
        Http::fake();

        Http::get($url);

        Http::assertSentCount(1);
    })->with([
        'https://api.salla.dev/admin/v2/products',
        'https://accounts.salla.sa/oauth2/token',
        'https://cdn.salla.sa/img.png',
        'https://bedrock-runtime.eu-west-1.amazonaws.com/model/x/converse',
        'https://queue.fal.run/fal-ai/model',
    ]);

    test('the allow-list can be switched off in configuration', function () {
        Http::fake();
        config(['revo.outbound.enforce_allowlist' => false]);

        Http::get('https://anywhere.example.org/');

        Http::assertSentCount(1);
    });
});

describe('admin accounts', function () {
    test('the console command creates the only kind of admin account', function () {
        $this->artisan('admin:create', ['email' => 'staff@revo.test', '--name' => 'Staff', '--password' => 'a-long-password'])->assertSuccessful();

        $admin = AdminUser::firstWhere('email', 'staff@revo.test');
        expect($admin->name)->toBe('Staff')
            ->and(Hash::check('a-long-password', $admin->password))->toBeTrue()
            ->and($admin->app_authentication_secret)->toBeNull();
    });

    test('a password is generated when none is given and duplicates are refused', function () {
        $this->artisan('admin:create', ['email' => 'staff@revo.test'])->expectsOutputToContain('Generated password')->assertSuccessful();
        $this->artisan('admin:create', ['email' => 'staff@revo.test'])->assertFailed();

        expect(AdminUser::count())->toBe(1);
    });

    test('two-factor secrets and recovery codes are encrypted at rest', function () {
        $admin = AdminUser::factory()->withAppAuthentication('SECRET123')->create();
        $admin->saveAppAuthenticationRecoveryCodes(['code-1', 'code-2']);

        $raw = DB::table('admin_users')->where('id', $admin->id)->first();
        expect($raw->app_authentication_secret)->not->toBe('SECRET123')
            ->and($raw->app_authentication_recovery_codes)->not->toContain('code-1')
            ->and($admin->fresh()->getAppAuthenticationRecoveryCodes())->toBe(['code-1', 'code-2']);
    });

    test('a merchant-facing user cannot reach the panel', function () {
        $this->actingAs(User::factory()->create());

        $this->get('/admin')->assertRedirect('/admin/login');
    });

    test('guests are sent to the admin login', function () {
        $this->get('/admin')->assertRedirect('/admin/login');
    });

    test('an admin with two-factor set up opens the dashboard', function () {
        $this->actingAs(AdminUser::factory()->withAppAuthentication()->create(), 'admin');

        $this->get('/admin')->assertOk();
    });

    test('an admin without two-factor is held at the setup page', function () {
        $this->actingAs(AdminUser::factory()->create(), 'admin');

        $this->get('/admin')->assertRedirect();
        expect($this->get('/admin')->headers->get('Location'))->toContain('multi-factor');
    });
});

describe('webhook timestamps', function () {
    test('both created_at formats are stored as the same instant', function () {
        config(['salla.webhook_secret' => 'secret']);
        $post = function (array $body) {
            $raw = json_encode($body);

            return $this->call('POST', '/api/webhooks/salla', [], [], [], [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_SALLA_SECURITY_STRATEGY' => 'Signature',
                'HTTP_X_SALLA_SIGNATURE' => hash_hmac('sha256', $raw, 'secret'),
            ], $raw);
        };

        $post(['event' => 'app.something.a', 'merchant' => 1, 'created_at' => 'Mon Apr 17 2023 12:14:21 GMT+0300', 'data' => []])->assertOk();
        $post(['event' => 'app.something.b', 'merchant' => 1, 'created_at' => '2023-04-17 12:14:21', 'data' => []])->assertOk();

        $times = AppEvent::orderBy('id')->pluck('event_created_at')->map->toDateTimeString()->all();
        expect($times)->toBe(['2023-04-17 09:14:21', '2023-04-17 09:14:21']);
    });
});
