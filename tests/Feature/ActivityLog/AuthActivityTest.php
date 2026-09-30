<?php

use App\Enums\ActivityLevel;
use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Auth\Events\Verified;
use Illuminate\Http\Request;
use Laravel\Fortify\Events\TwoFactorAuthenticationDisabled;
use Laravel\Fortify\Events\TwoFactorAuthenticationEnabled;
use Laravel\Fortify\Events\TwoFactorAuthenticationFailed;
use Laravel\Fortify\Features;

test('logging in and out is recorded against the user', function () {
    $user = User::factory()->create();

    $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password']);
    $this->post(route('logout'));

    expect(ActivityLog::where('action', 'user.login')->sole())
        ->channel->toBe('user')
        ->actor_id->toBe((string) $user->id)
        ->subject_id->toBe((string) $user->id)
        ->context->toMatchArray(['guard' => 'web'])
        ->and(ActivityLog::where('action', 'user.logout')->sole()->actor_id)->toBe((string) $user->id);
});

test('a failed login records the email but never the password', function () {
    $user = User::factory()->create();

    $this->post(route('login.store'), ['email' => $user->email, 'password' => 'wrong-password']);

    $entry = ActivityLog::where('action', 'user.login_failed')->sole();
    expect($entry->level)->toBe(ActivityLevel::Warning)
        ->and($entry->context['email'])->toBe($user->email)
        ->and(json_encode($entry->toArray()))->not->toContain('wrong-password');
});

test('registration is recorded', function () {
    $this->skipUnlessFortifyHas(Features::registration());

    $this->post(route('register.store'), [
        'name' => 'Test User',
        'email' => 'test@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    $user = User::firstWhere('email', 'test@example.com');
    expect(ActivityLog::where('action', 'user.registered')->sole()->subject_id)->toBe((string) $user->id);
});

test('account security events are recorded', function () {
    $user = User::factory()->create();
    $request = Request::create('/login', 'POST', ['email' => 'someone@example.com']);

    event(new PasswordReset($user));
    event(new Verified($user));
    event(new Lockout($request));
    event(new TwoFactorAuthenticationEnabled($user));
    event(new TwoFactorAuthenticationDisabled($user));
    event(new TwoFactorAuthenticationFailed($user));

    expect(ActivityLog::where('channel', 'user')->orderBy('id')->pluck('action')->all())->toBe([
        'user.password_reset',
        'user.email_verified',
        'user.lockout',
        'user.two_factor_enabled',
        'user.two_factor_disabled',
        'user.two_factor_failed',
    ]);
    expect(ActivityLog::where('action', 'user.lockout')->sole()->context['email'])->toBe('someone@example.com');
});

test('authentication logging can be switched off', function () {
    config(['activity-log.auth.enabled' => false]);
    $user = User::factory()->create();

    $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password']);

    expect(ActivityLog::where('channel', 'user')->count())->toBe(0);
});
