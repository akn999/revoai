<?php

use App\Salla\Handlers\AppInstalledHandler;
use App\Salla\Handlers\AppUninstalledHandler;
use App\Salla\Handlers\AppUpdatedHandler;
use App\Salla\Handlers\FeedbackCreatedHandler;
use App\Salla\Handlers\ProductDeletedHandler;
use App\Salla\Handlers\ProductImagesUpdatedHandler;
use App\Salla\Handlers\ProductUpsertHandler;
use App\Salla\Handlers\SettingsUpdatedHandler;
use App\Salla\Handlers\StoreAuthorizeHandler;
use App\Salla\Handlers\SubscriptionEndedHandler;
use App\Salla\Handlers\SubscriptionRenewedHandler;
use App\Salla\Handlers\SubscriptionStartedHandler;
use App\Salla\Handlers\TrialEndedHandler;
use App\Salla\Handlers\TrialStartedHandler;

return [
    'app_id' => env('SALLA_APP_ID'),
    'client_id' => env('SALLA_CLIENT_ID'),
    'client_secret' => env('SALLA_CLIENT_SECRET'),
    'webhook_secret' => env('SALLA_WEBHOOK_SECRET'),
    'oauth_url' => env('SALLA_OAUTH_URL', 'https://accounts.salla.sa/oauth2'),
    'introspect_url' => env('SALLA_INTROSPECT_URL', 'https://api.salla.dev/exchange-authority/v1/introspect'),
    'queue' => env('SALLA_WEBHOOK_QUEUE', 'webhooks'),
    'backoff' => [10, 30, 60, 300, 900],
    'max_exceptions' => 5,
    'failed_alert_threshold' => 10,

    'handlers' => [
        'app.store.authorize' => StoreAuthorizeHandler::class,
        'app.installed' => AppInstalledHandler::class,
        'app.updated' => AppUpdatedHandler::class,
        'app.uninstalled' => AppUninstalledHandler::class,
        'app.trial.started' => TrialStartedHandler::class,
        'app.trial.expired' => TrialEndedHandler::class,
        'app.trial.canceled' => TrialEndedHandler::class,
        'app.subscription.started' => SubscriptionStartedHandler::class,
        'app.subscription.renewed' => SubscriptionRenewedHandler::class,
        'app.subscription.canceled' => SubscriptionEndedHandler::class,
        'app.subscription.expired' => SubscriptionEndedHandler::class,
        'app.settings.updated' => SettingsUpdatedHandler::class,
        'app.feedback.created' => FeedbackCreatedHandler::class,
        'product.created' => ProductUpsertHandler::class,
        'product.updated' => ProductUpsertHandler::class,
        'product.deleted' => ProductDeletedHandler::class,
        'product.image.updated' => ProductImagesUpdatedHandler::class,
    ],
];
