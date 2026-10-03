<?php

namespace App\Products\Exceptions;

use RuntimeException;

class ReviewRejected extends RuntimeException
{
    /**
     * @param  array<string, array<int, string>>  $errors
     */
    public function __construct(string $message, public readonly array $errors = [])
    {
        parent::__construct($message);
    }
}
