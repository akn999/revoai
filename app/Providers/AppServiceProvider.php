<?php

namespace App\Providers;

use App\Ai\Providers\BedrockConverseProvider;
use App\Ai\Providers\FalProvider;
use App\Ai\Providers\ImageModelProvider;
use App\Ai\Providers\TextModelProvider;
use App\Billing\ReleaseReservationsOnUninstall;
use App\Listeners\LogAuthenticationActivity;
use App\Logging\ActivityContext;
use App\Logging\ActivityLogger;
use App\Logging\OutboundRequestLogger;
use App\Platform\EmbeddedSessionService;
use App\Platform\Events\StoreUninstalled;
use App\Platform\Listeners\RevokeSessionsOnUninstall;
use App\Platform\OutboundHostGuard;
use App\Platform\RevoSettings;
use App\Support\CurrentMerchant;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->scoped(CurrentMerchant::class);
        $this->app->scoped(ActivityContext::class);
        $this->app->singleton(ActivityLogger::class);
        $this->app->singleton(RevoSettings::class);
        $this->app->bind(TextModelProvider::class, BedrockConverseProvider::class);
        $this->app->bind(ImageModelProvider::class, FalProvider::class);
        $this->app->singleton(EmbeddedSessionService::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->configureActivityLog();
    }

    /**
     * Hook the database activity log into auth events and the outbound HTTP client.
     */
    protected function configureActivityLog(): void
    {
        Event::subscribe(LogAuthenticationActivity::class);
        Event::listen(StoreUninstalled::class, RevokeSessionsOnUninstall::class);
        Event::listen(StoreUninstalled::class, ReleaseReservationsOnUninstall::class);
        Http::globalMiddleware(new OutboundHostGuard);
        Http::globalMiddleware(new OutboundRequestLogger);

        RateLimiter::for('app-api', fn (Request $request): Limit => Limit::perMinute((int) config('revo.limits.api_requests_per_minute'))
            ->by((string) ($request->attributes->get('salla_session_id') ?? $request->ip())));
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
