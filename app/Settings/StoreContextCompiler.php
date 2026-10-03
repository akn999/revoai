<?php

namespace App\Settings;

use App\Models\ContextOption;
use App\Models\Merchant;
use App\Models\StoreContext;
use App\Models\StoreContextEntry;
use Illuminate\Support\Str;

/**
 * Builds the store-context text for one feature. Only the fields marked for that feature in
 * SRS 8.2 are included, and the result is capped (FR-SET-005, FR-AI-008).
 */
class StoreContextCompiler
{
    /** Which features read which store-context field (section 8.2). */
    public const FIELD_USE = [
        'store_name' => ['products', 'images', 'chat'],
        'tagline' => ['products', 'chat'],
        'slogan' => ['products', 'images'],
        'industry' => ['products', 'images', 'chat'],
        'audience' => ['products', 'images', 'chat'],
        'brand_tone' => ['products', 'chat'],
        'photography_style' => ['images'],
        'brand_colors' => ['images'],
        'delivery_policy' => ['chat'],
        'return_policy' => ['chat'],
        'privacy_policy' => ['chat'],
        'faq' => ['chat'],
    ];

    public const LABELS = [
        'store_name' => 'Store name', 'tagline' => 'Tagline', 'slogan' => 'Slogan', 'industry' => 'Industry',
        'audience' => 'Target audience', 'brand_tone' => 'Brand tone', 'photography_style' => 'Photography style',
        'brand_colors' => 'Brand colors', 'delivery_policy' => 'Delivery policy', 'return_policy' => 'Return policy',
        'privacy_policy' => 'Privacy policy', 'faq' => 'FAQ',
    ];

    public function compile(int $merchantId, string $feature, ?string $language = 'en'): string
    {
        $language ??= 'en';
        $context = StoreContext::query()->where('merchant_id', $merchantId)->first();
        $lines = [];

        if ($context) {
            foreach (self::FIELD_USE as $field => $features) {
                if (! in_array($feature, $features, true)) {
                    continue;
                }

                $value = $this->value($context, $field, $language);

                if ($value !== null && $value !== '') {
                    $lines[] = self::LABELS[$field].': '.$value;
                }
            }

            if ($feature === 'chat' && $context->returns_accepted !== null) {
                $lines[] = 'Returns accepted: '.($context->returns_accepted ? 'yes' : 'no');
            }
        }

        foreach (StoreContextEntry::query()->where('merchant_id', $merchantId)->orderBy('sort')->orderBy('id')->get() as $entry) {
            $lines[] = "{$entry->label}: {$entry->text}";
        }

        return $this->cap(implode("\n", $lines));
    }

    private function cap(string $text): string
    {
        $maxChars = (int) config('revo.limits.store_context_tokens') * 4;

        return mb_strlen($text) > $maxChars ? rtrim(Str::limit($text, $maxChars, '')) : $text;
    }

    private function value(StoreContext $context, string $field, string $language): ?string
    {
        $value = $context->{$field};

        if ($field === 'audience') {
            return collect($value ?? [])->map(fn (string $key): string => $this->label('audience', $key, $language))->implode(', ') ?: null;
        }

        if (in_array($field, ['industry', 'brand_tone', 'photography_style'], true) && is_string($value)) {
            return $this->label($field, $value, $language);
        }

        if ($field === 'brand_colors') {
            return collect($value ?? [])->implode(', ') ?: null;
        }

        if ($field === 'faq') {
            return collect($value ?? [])->map(fn (array $pair): string => 'Q: '.($pair['question'] ?? '').' A: '.($pair['answer'] ?? ''))->implode(' | ') ?: null;
        }

        return is_string($value) ? $value : null;
    }

    private function label(string $field, string $key, string $language): string
    {
        $option = ContextOption::query()->where('field', $field)->where('key', $key)->first();

        return $option ? ($language === 'ar' ? $option->label_ar : $option->label_en) : $key;
    }

    public function forMerchant(Merchant $merchant, string $feature): string
    {
        return $this->compile($merchant->merchant_id, $feature, $merchant->default_language);
    }
}
