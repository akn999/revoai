<?php

use App\Billing\Exceptions\InsufficientCredits;
use App\Http\Middleware\AuthenticateEmbeddedSession;
use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\LogRequests;
use App\Images\Exceptions\InvalidImage;
use App\Logging\Activity;
use App\Moderation\ModerationBlocked;
use App\Moderation\ModerationUnavailable;
use App\Products\Exceptions\OutdatedDraft;
use App\Products\Exceptions\PlanLocked;
use App\Products\Exceptions\PushFailed;
use App\Products\Exceptions\ReviewRejected;
use App\Settings\SettingsException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->encryptCookies(except: ['appearance', 'sidebar_state']);

        $middleware->web(append: [
            HandleAppearance::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
            LogRequests::class.':web',
        ]);

        $middleware->prependToPriorityList(
            before: ThrottleRequests::class,
            prepend: AuthenticateEmbeddedSession::class,
        );

        $middleware->api(append: [
            LogRequests::class.':api',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->report(function (Throwable $exception): void {
            if (config('activity-log.exceptions.enabled', true)) {
                Activity::channel('system')->bySystem()->withException($exception)
                    ->error('exception.reported', $exception->getMessage());
            }
        });

        $exceptions->render(function (InsufficientCredits $e, Request $request) {
            return response()->json(['message' => 'Not enough credits.', 'required' => $e->required, 'available' => $e->available], 402);
        });
        $exceptions->render(fn (PlanLocked $e, Request $request) => response()->json(['message' => $e->getMessage(), 'feature' => $e->feature], 403));
        $exceptions->render(fn (OutdatedDraft $e, Request $request) => response()->json(['message' => $e->getMessage(), 'code' => 'outdated'], 409));
        $exceptions->render(fn (PushFailed $e, Request $request) => response()->json(['message' => $e->getMessage()], 502));
        $exceptions->render(fn (ReviewRejected $e, Request $request) => response()->json(['message' => $e->getMessage(), 'errors' => $e->errors], 422));
        $exceptions->render(fn (SettingsException $e, Request $request) => response()->json(['message' => $e->getMessage(), 'errors' => $e->errors], 422));
        $exceptions->render(fn (ModerationBlocked $e, Request $request) => response()->json(['message' => $e->getMessage(), 'category' => $e->category], 422));
        $exceptions->render(fn (ModerationUnavailable $e, Request $request) => response()->json(['message' => 'Content checks are unavailable right now. Try again shortly.'], 503));
        $exceptions->render(fn (InvalidImage $e, Request $request) => response()->json(['message' => $e->getMessage()], 422));
        $exceptions->render(fn (InvalidArgumentException $e, Request $request) => $request->is('api/app/*') ? response()->json(['message' => $e->getMessage()], 422) : null);

        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
