<?php

namespace App\Platform\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Fired just before a store's tenant rows are deleted, so modules can remove their files (FR-INS-006).
 */
class StorePurging
{
    use Dispatchable;

    public function __construct(public int $merchantId) {}
}
