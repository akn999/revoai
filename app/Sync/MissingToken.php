<?php

namespace App\Sync;

use RuntimeException;

class MissingToken extends RuntimeException
{
    public function __construct(int $merchantId)
    {
        parent::__construct("Merchant {$merchantId} has no usable Salla access token.");
    }
}
