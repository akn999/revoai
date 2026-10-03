<?php

namespace App\Products;

/**
 * The single source of truth for which fields exist and how long they may be (section 5.1).
 */
class FieldLimits
{
    public const TEXT_FIELDS = ['name', 'description', 'promotion_title', 'subtitle', 'metadata_title', 'metadata_description', 'metadata_url'];

    public const ALL_FIELDS = [...self::TEXT_FIELDS, 'alt'];

    /** Column on product_translations for each field. */
    public const COLUMNS = [
        'name' => 'name', 'description' => 'description', 'promotion_title' => 'promotion_title', 'subtitle' => 'subtitle',
        'metadata_title' => 'metadata_title', 'metadata_description' => 'metadata_description', 'metadata_url' => 'metadata_url',
    ];

    public static function limit(string $field): ?int
    {
        $limit = config("revo.product.field_limits.{$field}");

        return $limit === null ? null : (int) $limit;
    }

    /**
     * Fields over their character limit.
     *
     * @param  array<string, mixed>  $values
     * @return array<int, string>
     */
    public static function overLimit(array $values): array
    {
        $over = [];

        foreach ($values as $field => $value) {
            $limit = self::limit($field);

            if ($limit !== null && is_string($value) && mb_strlen($value) > $limit) {
                $over[] = $field;
            }
        }

        return $over;
    }

    public static function isSeo(string $field): bool
    {
        return str_starts_with($field, 'metadata_');
    }
}
