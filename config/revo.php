<?php

use App\Models\AppFeedback;
use App\Models\BulkJob;
use App\Models\ContentGeneration;
use App\Models\ContentVersion;
use App\Models\FormDraft;
use App\Models\GeneratedImage;
use App\Models\ImageAnalysis;
use App\Models\ImageGeneration;
use App\Models\MediaFolder;
use App\Models\MediaTag;
use App\Models\MerchantSetting;
use App\Models\MerchantToken;
use App\Models\Preset;
use App\Models\Product;
use App\Models\ProductContext;
use App\Models\ProductImage;
use App\Models\ProductImageAlt;
use App\Models\ProductTranslation;
use App\Models\StoreContext;
use App\Models\StoreContextEntry;
use App\Models\StorePrompt;
use App\Models\StoreSetting;

/*
| Revo AI product configuration. Everything here can be changed without a code
| change; values an admin edits in the panel live in the `app_settings` table
| and override the matching entries below (see App\Platform\RevoSettings).
*/
return [
    'timezone' => env('REVO_TIMEZONE', 'Asia/Riyadh'),

    'prices' => [
        'product_content' => 8,
        'field_regeneration' => 2,
        'image_edit' => 20,
    ],

    'starter_credits' => 100,
    'low_balance_threshold' => 40,

    /*
    | Which PurchaseVerifier strategy confirms credit purchases.
    | client_result: trusts the Salla checkout result (phase 1 default).
    | salla_confirmed: server-side confirmation (placeholder until Salla documents one).
    */
    'purchase_verifier' => env('REVO_PURCHASE_VERIFIER', 'client_result'),

    'limits' => [
        'session_idle_minutes' => 60,
        'session_absolute_hours' => 8,
        'api_requests_per_minute' => 120,
        'draft_days' => 7,
        'image_days' => 60,
        'purge_grace_days' => 60,
        'webhook_days' => 90,
        'signed_url_minutes' => 15,
        'reservation_stale_minutes' => 60,
        'intent_hours' => 24,
        'image_timeout_minutes' => 5,
        'text_timeout_seconds' => 60,
        'product_context_tokens' => 2000,
        'store_context_tokens' => 1500,
        'store_context_field_chars' => 5000,
        'upload_megabytes' => 10,
        'expiry_warning_days' => 7,
        'token_refresh_days' => 3,
        'bulk_concurrency' => 3,
        'ai_global_rate_per_minute' => 60,
        'autosave_debounce_seconds' => 2,
    ],

    'salla' => [
        'api_url' => env('SALLA_API_URL', 'https://api.salla.dev/admin/v2'),
        'cdn_hosts' => ['cdn.salla.sa', 'cdn.salla.network'],
        'page_size' => 60,
        'retry_attempts' => 5,
        'sync_window_minutes' => 15,
        'language_fetch_debounce_seconds' => 60,
        'dashboard_origins' => ['https://s.salla.sa'],
    ],

    /*
    | Outbound HTTP is limited to these hosts (NFR-SEC-005). `*` matches any characters.
    */
    'outbound' => [
        'enforce_allowlist' => env('REVO_ENFORCE_OUTBOUND_ALLOWLIST', true),
        'allowed_hosts' => [
            'api.salla.dev',
            'accounts.salla.sa',
            'cdn.salla.sa',
            'cdn.salla.network',
            'bedrock-runtime.*.amazonaws.com',
            '*.fal.run',
            'fal.run',
            '*.fal.media',
            'fal.media',
            '*.s3.*.amazonaws.com',
            '*.amazonaws.com',
        ],
    ],

    'features' => [
        'product_content', 'field_regeneration', 'image_edit', 'image_analysis',
        'text_moderation', 'image_moderation', 'chat',
    ],

    'product' => [
        'languages' => ['ar', 'en'],
        'field_limits' => [
            'name' => 100,
            'promotion_title' => 25,
            'subtitle' => 120,
            'metadata_title' => 100,
            'metadata_description' => 100,
            'metadata_url' => 100,
            'alt' => 100,
        ],
        'description_words' => [
            'short' => [50, 100],
            'medium' => [150, 250],
            'long' => [300, 500],
        ],
        'html_tags' => ['h2', 'h3', 'p', 'ul', 'ol', 'li', 'strong', 'em', 'u', 's', 'br'],
        'rtl_class' => 'ql-direction-rtl',
    ],

    'images' => [
        'max_variants' => 3,
        'default_variants' => 1,
        'mimes' => ['image/jpeg', 'image/png', 'image/webp'],
        'disk' => env('REVO_IMAGE_DISK', 'local'),
    ],

    'ai' => [
        'bedrock' => [
            'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
        ],
        'fal' => [
            'key' => env('FAL_KEY'),
            'queue_url' => env('FAL_QUEUE_URL', 'https://queue.fal.run'),
            'callback_ttl_minutes' => 30,
        ],
    ],

    /*
    | Every model carrying a `merchant_id` that is deleted when a store is purged. Billing
    | records (wallet, ledger, purchases, subscriptions) and audit logs are deliberately absent.
    */
    'tenant_models' => [
        GeneratedImage::class,
        ImageGeneration::class,
        MediaFolder::class,
        MediaTag::class,
        ProductImageAlt::class,
        ContentVersion::class,
        ContentGeneration::class,
        BulkJob::class,
        ProductImage::class,
        ProductContext::class,
        ProductTranslation::class,
        Product::class,
        ImageAnalysis::class,
        FormDraft::class,
        StoreContextEntry::class,
        StoreContext::class,
        StorePrompt::class,
        StoreSetting::class,
        Preset::class,
        MerchantToken::class,
        MerchantSetting::class,
        AppFeedback::class,
    ],

    'moderation_platform_rules' => 'Keep the product itself unchanged unless the instruction asks otherwise. Any person that is generated or edited must be modestly dressed.',
];
