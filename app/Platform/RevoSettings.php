<?php

namespace App\Platform;

use App\Models\AppSetting;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;

/**
 * Reads a Revo setting: the admin-edited value in `app_settings` wins over `config/revo.php`.
 */
class RevoSettings
{
    private const CACHE_KEY = 'revo:app_settings';

    public function get(string $key, mixed $default = null): mixed
    {
        $overrides = $this->overrides();

        if (array_key_exists($key, $overrides) && $overrides[$key] !== null) {
            return $overrides[$key];
        }

        return config("revo.$key", $default);
    }

    public function set(string $key, mixed $value): void
    {
        AppSetting::query()->updateOrCreate(['key' => $key], ['value' => $value]);
        Cache::forget(self::CACHE_KEY);
    }

    public function forget(string $key): void
    {
        AppSetting::query()->whereKey($key)->delete();
        Cache::forget(self::CACHE_KEY);
    }

    public function price(string $action): int
    {
        return (int) $this->get("prices.$action", 0);
    }

    public function starterCredits(): int
    {
        return (int) $this->get('starter_credits', 0);
    }

    public function lowBalanceThreshold(): int
    {
        return (int) $this->get('low_balance_threshold', 0);
    }

    public function purchaseVerifier(): string
    {
        return (string) $this->get('purchase_verifier', 'client_result');
    }

    /**
     * @return array<string, mixed>
     */
    private function overrides(): array
    {
        return Cache::remember(self::CACHE_KEY, 60, fn (): array => AppSetting::query()
            ->pluck('value', 'key')
            ->all());
    }

    /**
     * All settings flattened, for tests and the admin form.
     *
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return Arr::dot(config('revo')) + $this->overrides();
    }
}
