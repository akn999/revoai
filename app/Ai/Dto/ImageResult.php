<?php

namespace App\Ai\Dto;

final class ImageResult
{
    /**
     * @param  array<int, string>  $urls  One URL per generated variant.
     */
    public function __construct(public readonly array $urls, public readonly ?string $requestId = null) {}
}
