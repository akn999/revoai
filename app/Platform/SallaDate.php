<?php

namespace App\Platform;

use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Date;
use Throwable;

/**
 * Parses the two created_at formats Salla sends:
 *  - "Mon Apr 17 2023 12:14:21 GMT+0300"  (carries an offset)
 *  - "2026-10-02 10:43:00"                (no offset: read as Asia/Riyadh)
 * The result is always in the application timezone.
 */
class SallaDate
{
    public static function parse(mixed $value): ?CarbonInterface
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        $value = trim($value);

        try {
            $parsed = preg_match('/GMT[+-]\d{4}|[+-]\d{2}:?\d{2}$|Z$/', $value) === 1
                ? Date::parse($value)
                : Date::parse($value, (string) config('revo.timezone', 'Asia/Riyadh'));
        } catch (Throwable) {
            return null;
        }

        return $parsed->timezone(config('app.timezone'));
    }
}
