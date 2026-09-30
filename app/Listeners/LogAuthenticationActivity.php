<?php

namespace App\Listeners;

use App\Logging\Activity;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Auth\Events\Registered;
use Illuminate\Auth\Events\Verified;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Events\Dispatcher;
use Laravel\Fortify\Events\TwoFactorAuthenticationConfirmed;
use Laravel\Fortify\Events\TwoFactorAuthenticationDisabled;
use Laravel\Fortify\Events\TwoFactorAuthenticationEnabled;
use Laravel\Fortify\Events\TwoFactorAuthenticationFailed;

class LogAuthenticationActivity
{
    /**
     * @return array<class-string, string>
     */
    public function subscribe(Dispatcher $events): array
    {
        return [
            Login::class => 'onLogin',
            Logout::class => 'onLogout',
            Failed::class => 'onFailed',
            Lockout::class => 'onLockout',
            Registered::class => 'onRegistered',
            Verified::class => 'onVerified',
            PasswordReset::class => 'onPasswordReset',
            TwoFactorAuthenticationEnabled::class => 'onTwoFactorEnabled',
            TwoFactorAuthenticationConfirmed::class => 'onTwoFactorConfirmed',
            TwoFactorAuthenticationDisabled::class => 'onTwoFactorDisabled',
            TwoFactorAuthenticationFailed::class => 'onTwoFactorFailed',
        ];
    }

    public function onLogin(Login $event): void
    {
        $this->record('user.login', 'User logged in', $event->user, ['guard' => $event->guard, 'remember' => $event->remember]);
    }

    public function onLogout(Logout $event): void
    {
        $this->record('user.logout', 'User logged out', $event->user, ['guard' => $event->guard]);
    }

    public function onFailed(Failed $event): void
    {
        $this->record('user.login_failed', 'Login attempt failed', $event->user, [
            'guard' => $event->guard,
            'email' => $event->credentials['email'] ?? null,
        ], 'warning');
    }

    public function onLockout(Lockout $event): void
    {
        $this->record('user.lockout', 'Too many login attempts', null, ['email' => $event->request->input('email')], 'warning');
    }

    public function onRegistered(Registered $event): void
    {
        $this->record('user.registered', 'User registered', $event->user);
    }

    public function onVerified(Verified $event): void
    {
        $this->record('user.email_verified', 'Email verified', $event->user);
    }

    public function onPasswordReset(PasswordReset $event): void
    {
        $this->record('user.password_reset', 'Password reset', $event->user);
    }

    public function onTwoFactorEnabled(TwoFactorAuthenticationEnabled $event): void
    {
        $this->record('user.two_factor_enabled', 'Two-factor authentication enabled', $event->user);
    }

    public function onTwoFactorConfirmed(TwoFactorAuthenticationConfirmed $event): void
    {
        $this->record('user.two_factor_confirmed', 'Two-factor authentication confirmed', $event->user);
    }

    public function onTwoFactorDisabled(TwoFactorAuthenticationDisabled $event): void
    {
        $this->record('user.two_factor_disabled', 'Two-factor authentication disabled', $event->user);
    }

    public function onTwoFactorFailed(TwoFactorAuthenticationFailed $event): void
    {
        $this->record('user.two_factor_failed', 'Two-factor authentication failed', $event->user, [], 'warning');
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function record(string $action, string $message, ?object $user, array $context = [], string $level = 'info'): void
    {
        if (! config('activity-log.auth.enabled', true)) {
            return;
        }

        $pending = Activity::channel('user')->with($context);

        if ($user instanceof Model) {
            $pending->by($user)->on($user);
        }

        $pending->log($action, $message, $level);
    }
}
