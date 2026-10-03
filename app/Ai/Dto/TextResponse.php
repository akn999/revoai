<?php

namespace App\Ai\Dto;

final class TextResponse
{
    /**
     * @param  array<string, mixed>|null  $toolInput
     */
    public function __construct(
        public readonly string $text,
        public readonly ?array $toolInput,
        public readonly int $inputTokens,
        public readonly int $outputTokens,
        public readonly string $stopReason = 'end_turn',
        public readonly ?string $requestId = null,
    ) {}
}
