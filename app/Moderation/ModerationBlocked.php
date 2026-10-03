<?php

namespace App\Moderation;

use RuntimeException;

/**
 * Content was blocked. Merchants only ever see the category name, never scores or model details (FR-MOD-002).
 */
class ModerationBlocked extends RuntimeException
{
    public function __construct(public readonly string $category, public readonly string $categoryName)
    {
        parent::__construct("Blocked: {$categoryName}");
    }
}
