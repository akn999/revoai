<?php

namespace App\Products\Exceptions;

use RuntimeException;

class PushFailed extends RuntimeException
{
    public function __construct(?\Throwable $previous = null)
    {
        parent::__construct('Salla did not accept the update. The draft is kept; you can retry at no cost.', 0, $previous);
    }
}
