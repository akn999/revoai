<?php

namespace App\Products;

use Illuminate\Support\Str;

/**
 * Turns generated text into a slug for metadata.url (FR-PRD-009): at most 100 characters, no spaces,
 * words joined by hyphens. English slugs are lowercase Latin; Arabic slugs keep Arabic letters.
 */
class SlugGenerator
{
    public function make(string $text, string $language): string
    {
        $limit = (int) config('revo.product.field_limits.metadata_url');

        $slug = $language === 'ar' ? '' : Str::slug($text);

        if ($slug === '') {
            $slug = mb_strtolower(trim((string) preg_replace('/[^\p{L}\p{N}]+/u', '-', $text), '-'));
        }

        return trim(mb_substr($slug, 0, $limit), '-');
    }
}
