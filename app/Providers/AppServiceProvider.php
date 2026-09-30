<?php

namespace App\Providers;

use App\Listeners\LogAuthenticationActivity;
use App\Logging\ActivityContext;
use App\Logging\ActivityLogger;
use App\Logging\OutboundRequestLogger;
use App\Support\CurrentMerchant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
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
        Http::globalMiddleware(new OutboundRequestLogger);
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
