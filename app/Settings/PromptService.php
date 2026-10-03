<?php

namespace App\Settings;

use App\Models\PromptDefault;
use App\Models\StorePrompt;

/**
 * Prompts: a store follows the global default until it saves an override; resetting deletes the
 * override, so the store follows later changes to the default again (FR-SET-003).
 */
class PromptService
{
    public function effective(int $merchantId, string $key): string
    {
        $override = StorePrompt::query()->where('merchant_id', $merchantId)->where('key', $key)->value('body');

        return $override ?? (string) PromptDefault::query()->where('key', $key)->value('body');
    }

    public function override(int $merchantId, string $key): ?string
    {
        return StorePrompt::query()->where('merchant_id', $merchantId)->where('key', $key)->value('body');
    }

    public function saveOverride(int $merchantId, string $key, string $body): StorePrompt
    {
        return StorePrompt::query()->updateOrCreate(['merchant_id' => $merchantId, 'key' => $key], ['body' => $body]);
    }

    public function reset(int $merchantId, string $key): void
    {
        StorePrompt::query()->where('merchant_id', $merchantId)->where('key', $key)->delete();
    }

    /**
     * Every prompt with its default and the store's override, for the Settings screen.
     *
     * @return array<string, array{label: string, default: string, override: string|null, effective: string}>
     */
    public function all(int $merchantId): array
    {
        $overrides = StorePrompt::query()->where('merchant_id', $merchantId)->pluck('body', 'key');

        return PromptDefault::query()->orderBy('id')->get()->mapWithKeys(fn (PromptDefault $default): array => [
            $default->key => [
                'label' => $default->label,
                'default' => $default->body,
                'override' => $overrides[$default->key] ?? null,
                'effective' => $overrides[$default->key] ?? $default->body,
            ],
        ])->all();
    }
}
