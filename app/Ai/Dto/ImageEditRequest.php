<?php

namespace App\Ai\Dto;

final class ImageEditRequest
{
    /**
     * @param  array<string, mixed>  $parameters
     */
    public function __construct(
        public readonly string $modelId,
        public readonly string $imageUrl,
        public readonly string $prompt,
        public readonly array $parameters = [],
        public readonly int $variants = 1,
        public readonly ?string $callbackUrl = null,
    ) {}
}
