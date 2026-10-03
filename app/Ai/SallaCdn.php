<?php

namespace App\Ai;

use InvalidArgumentException;

/**
 * Salla-hosted images are only ever fetched from Salla CDN hosts (FR-MED-008).
 */
class SallaCdn
{
    public static function isAllowed(string $url): bool
    {
        $parts = parse_url($url);

        return ($parts['scheme'] ?? null) === 'https'
            && in_array(strtolower($parts['host'] ?? ''), (array) config('revo.salla.cdn_hosts'), true);
    }

    public static function assertAllowed(string $url): string
    {
        if (! self::isAllowed($url)) {
            throw new InvalidArgumentException('Only images hosted on Salla can be used as an edit source.');
        }

        return $url;
    }
}
