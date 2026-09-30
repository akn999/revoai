<?php

use App\Models\AppEvent;
use App\Models\Merchant;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;

const SALLA_TEST_SECRET = 'test-webhook-secret';
const SALLA_TEST_MERCHANT = 1234509876;

/** Event time offset in minutes from the frozen clock. */
function at(int $minutes = 0): string
{
    return Date::now()->addMinutes($minutes)->toDateTimeString();
}

/**
 * @param  array<string, mixed>  $data
 * @return array<string, mixed>
 */
function sallaEvent(string $event, array $data = [], int $minutes = 0, int $merchant = SALLA_TEST_MERCHANT): array
{
    return ['event' => $event, 'merchant' => $merchant, 'created_at' => at($minutes), 'data' => $data];
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function planData(array $overrides = []): array
{
    $start = Date::now()->subDays(5);

    return array_merge([
        'id' => 6789012345,
        'subscription_id' => 1510766049,
        'item_type' => 'plan',
        'item_slug' => null,
        'quantity' => 1,
        'app_name' => 'Shipping app',
        'plan_type' => 'recurring',
        'plan_name' => null,
        'plan_period' => '1',
        'start_date' => $start->toDateString(),
        'end_date' => $start->addMonth()->toDateString(),
        'coupon' => ['name' => 'SPZGRDFS', 'amount' => '0.15'],
        'initialization_cost' => 10,
        'price_before_discount' => 5,
        'price' => '20.00',
        'tax' => '0.15',
        'tax_value' => '3.00',
        'total' => '23.00',
        'subscription_balance' => 'null',
        'features' => [['key' => 'Feature1', 'quantity' => 1], ['key' => 'Feature3', 'quantity' => 5]],
        'store_type' => 'live',
    ], $overrides);
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function addonData(array $overrides = []): array
{
    return planData(array_merge([
        'item_type' => 'addon',
        'item_slug' => 'addon_chat_support',
        'plan_name' => 'Addon Chat Support',
        'quantity' => 3,
        'plan_type' => 'one_time',
        'start_date' => null,
        'end_date' => null,
    ], $overrides));
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function authorizeData(array $overrides = []): array
{
    return array_merge([
        'access_token' => 'plain-access-token',
        'refresh_token' => 'plain-refresh-token',
        'expires' => Date::now()->addDays(14)->timestamp,
        'scope' => 'settings.read offline_access',
        'token_type' => 'bearer',
    ], $overrides);
}

/**
 * Post a signed webhook exactly as Salla would.
 *
 * @param  array<string, mixed>|string  $body
 */
function deliverSalla(array|string $body, ?string $secret = SALLA_TEST_SECRET): TestResponse
{
    $raw = is_string($body) ? $body : json_encode($body, JSON_UNESCAPED_UNICODE);

    return test()->call('POST', '/api/webhooks/salla', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_SALLA_SECURITY_STRATEGY' => 'Signature',
        'HTTP_X_SALLA_SIGNATURE' => hash_hmac('sha256', $raw, (string) $secret),
    ], $raw);
}

function sallaMerchant(int $merchantId = SALLA_TEST_MERCHANT): Merchant
{
    return Merchant::withTrashed()->where('merchant_id', $merchantId)->firstOrFail();
}

function lastEvent(): AppEvent
{
    return AppEvent::latest('id')->firstOrFail();
}

/**
 * @param  array<string, mixed>  $overrides
 */
function fakeUserInfo(int $merchantId = SALLA_TEST_MERCHANT, array $overrides = []): void
{
    Http::fake([
        'accounts.salla.sa/oauth2/user/info' => Http::response(['data' => array_merge([
            'id' => 987654,
            'name' => 'Store Owner',
            'email' => 'owner@example.com',
            'mobile' => '+966500000000',
            'merchant' => [
                'id' => $merchantId,
                'name' => 'Cool Store',
                'domain' => 'https://cool.salla.sa',
                'avatar' => 'https://cdn.salla.sa/avatar.png',
            ],
        ], $overrides)]),
    ]);
}
