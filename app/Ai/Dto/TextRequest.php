<?php

namespace App\Ai\Dto;

/**
 * A provider-neutral text call. `tool` makes the model return structured output (Converse tool use).
 */
final class TextRequest
{
    /**
     * @param  array<int, array{role: string, content: string}>  $messages
     * @param  array{name: string, description: string, schema: array<string, mixed>}|null  $tool
     */
    public function __construct(
        public readonly string $modelId,
        public readonly string $system,
        public readonly array $messages,
        public readonly ?array $tool = null,
        public readonly int $maxTokens = 2048,
        public readonly float $temperature = 0.4,
    ) {}
}
