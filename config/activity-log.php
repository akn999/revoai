<?php

use App\Logging\Enrichers\ActorEnricher;
use App\Logging\Enrichers\CorrelationEnricher;
use App\Logging\Enrichers\MerchantEnricher;
use App\Logging\Enrichers\RequestEnricher;

return [
    /*
    | Master switch. When false nothing is written and every call is a no-op.
    */
    'enabled' => env('ACTIVITY_LOG_ENABLED', true),

    /*
    | Days to keep rows. The scheduled `model:prune` run deletes older rows.
    | Set to 0 to keep everything forever.
    */
    'retention_days' => (int) env('ACTIVITY_LOG_RETENTION_DAYS', 14),

    'connection' => env('ACTIVITY_LOG_DB_CONNECTION'),

    'table' => 'activity_logs',

    /*
    | Laravel log channel used when a row cannot be written (database down,
    | table missing). Must not include the `database` channel.
    */
    'fallback_channel' => env('ACTIVITY_LOG_FALLBACK_CHANNEL', 'stderr'),

    /*
    | Maximum JSON size of a row's context. Larger contexts are replaced by a
    | truncated preview so one huge payload cannot bloat the table.
    */
    'max_context_bytes' => (int) env('ACTIVITY_LOG_MAX_CONTEXT_BYTES', 60000),

    /*
    | Any context key containing one of these fragments (case-insensitive) has
    | its value replaced by "[redacted]" before it is stored.
    */
    'redact' => [
        'password', 'secret', 'token', 'authorization', 'cookie',
        'signature', 'api_key', 'apikey', 'credit_card', 'card_number', 'cvv',
    ],

    /*
    | Classes that fill in ambient data (request, actor, merchant, correlation
    | id) on every entry. Each must implement App\Logging\Enrichers\ActivityEnricher.
    */
    'enrichers' => [
        CorrelationEnricher::class,
        RequestEnricher::class,
        ActorEnricher::class,
        MerchantEnricher::class,
    ],

    /*
    | Inbound HTTP request logging (App\Http\Middleware\LogRequests).
    | `groups` maps a middleware group to the channel its requests log under;
    | `route_channels` overrides the channel for matching route names.
    */
    'http' => [
        'enabled' => env('ACTIVITY_LOG_HTTP', true),
        'groups' => ['web' => 'web', 'api' => 'api'],
        'route_channels' => ['webhooks.*' => 'webhook'],
        'exclude' => ['up', 'build/*', 'storage/*', 'favicon.ico', 'robots.txt', 'filament/*', 'livewire*/*.js', 'livewire*/update'],
    ],

    /*
    | Outbound HTTP client calls made through Laravel's Http facade.
    */
    'outbound' => [
        'enabled' => env('ACTIVITY_LOG_OUTBOUND', true),
    ],

    /*
    | Eloquent create/update/delete logging for models using the LogsActivity trait.
    */
    'models' => [
        'enabled' => env('ACTIVITY_LOG_MODELS', true),
    ],

    /*
    | Login, logout, failed login, password reset and two-factor events.
    */
    'auth' => [
        'enabled' => env('ACTIVITY_LOG_AUTH', true),
    ],

    /*
    | Reportable exceptions (the ones Laravel would write to the log).
    */
    'exceptions' => [
        'enabled' => env('ACTIVITY_LOG_EXCEPTIONS', true),
    ],
];
