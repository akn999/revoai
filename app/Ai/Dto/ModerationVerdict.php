<?php

namespace App\Ai\Dto;

final class ModerationVerdict
{
    /**
     * @param  string|null  $category  Key of the violated moderation category when blocked.
     */
    public function __construct(
        public readonly bool $blocked,
        public readonly ?string $category = null,
        public readonly ?string $requestId = null,
        public readonly int $inputTokens = 0,
        public readonly int $outputTokens = 0,
    ) {}

    public static function allowed(): self
    {
        return new self(false);
    }

    public static function blocked(string $category): self
    {
        return new self(true, $category);
    }
}
