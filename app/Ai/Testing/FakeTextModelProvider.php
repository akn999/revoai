<?php

namespace App\Ai\Testing;

use App\Ai\Dto\TextRequest;
use App\Ai\Dto\TextResponse;
use App\Ai\Providers\TextModelProvider;
use Closure;
use Throwable;

/**
 * Scripted text provider used by every test: no network, full recording.
 */
class FakeTextModelProvider implements TextModelProvider
{
    /** @var array<int, TextRequest> */
    public array $requests = [];

    /** @var array<int, TextResponse|Throwable|Closure> */
    private array $queue = [];

    private ?Closure $handler = null;

    /**
     * Queue responses (or throwables) returned in order, then fall back to the default handler.
     */
    public function queue(TextResponse|Throwable|Closure ...$responses): self
    {
        array_push($this->queue, ...$responses);

        return $this;
    }

    /**
     * Replace the default handler: (TextRequest): TextResponse|array<string,mixed>|string.
     */
    public function using(Closure $handler): self
    {
        $this->handler = $handler;

        return $this;
    }

    public function converse(TextRequest $request): TextResponse
    {
        $this->requests[] = $request;

        $next = array_shift($this->queue);

        if ($next instanceof Throwable) {
            throw $next;
        }

        $value = $next instanceof Closure ? $next($request) : ($next ?? ($this->handler ? ($this->handler)($request) : $this->default($request)));

        return $this->normalize($value, $request);
    }

    public function lastRequest(): ?TextRequest
    {
        return $this->requests[array_key_last($this->requests)] ?? null;
    }

    /**
     * @param  TextResponse|array<string, mixed>|string  $value
     */
    private function normalize(mixed $value, TextRequest $request): TextResponse
    {
        if ($value instanceof TextResponse) {
            return $value;
        }

        if (is_array($value)) {
            return $request->tool !== null
                ? new TextResponse('', $value, 120, 60, 'tool_use', 'fake-text-'.count($this->requests))
                : new TextResponse((string) json_encode($value, JSON_UNESCAPED_UNICODE), null, 120, 60, 'end_turn', 'fake-text-'.count($this->requests));
        }

        return new TextResponse((string) $value, null, 120, 60, 'end_turn', 'fake-text-'.count($this->requests));
    }

    /**
     * @return array<string, mixed>|string
     */
    private function default(TextRequest $request): array|string
    {
        if ($request->tool === null) {
            return 'ok';
        }

        $properties = (array) data_get($request->tool, 'schema.properties', []);

        return array_map(fn (array $property) => match ($property['type'] ?? 'string') {
            'boolean' => false,
            'integer', 'number' => 0,
            'array' => [],
            'object' => (object) [],
            default => 'sample',
        }, $properties);
    }
}
