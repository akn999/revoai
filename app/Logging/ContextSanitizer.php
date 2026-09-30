<?php

namespace App\Logging;

use BackedEnum;
use DateTimeInterface;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Database\Eloquent\Model;
use JsonSerializable;
use Stringable;
use Throwable;
use UnitEnum;

/**
 * Turns arbitrary context into JSON-safe data and masks secrets.
 */
class ContextSanitizer
{
    private const MAX_DEPTH = 6;

    public const REDACTED = '[redacted]';

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public function sanitize(array $context): array
    {
        return $this->walk($context, 0);
    }

    /**
     * Keep the encoded context under the configured size.
     *
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public function limit(array $context): array
    {
        $max = (int) config('activity-log.max_context_bytes', 60000);
        $json = json_encode($context, JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR) ?: '';

        if ($max <= 0 || strlen($json) <= $max) {
            return $context;
        }

        return [
            '_truncated' => true,
            '_original_bytes' => strlen($json),
            'preview' => mb_strcut($json, 0, max($max - 200, 0)),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function exception(Throwable $exception): array
    {
        return [
            'class' => $exception::class,
            'message' => $exception->getMessage(),
            'code' => $exception->getCode(),
            'file' => $exception->getFile().':'.$exception->getLine(),
            'trace' => array_slice(explode("\n", $exception->getTraceAsString()), 0, 15),
        ];
    }

    private function walk(mixed $value, int $depth): mixed
    {
        if ($depth > self::MAX_DEPTH) {
            return '[max depth]';
        }

        return match (true) {
            $value === null, is_bool($value), is_int($value) => $value,
            is_float($value) => is_finite($value) ? $value : (string) $value,
            is_string($value) => mb_scrub($value),
            is_array($value) => $this->walkArray($value, $depth),
            $value instanceof BackedEnum => $value->value,
            $value instanceof UnitEnum => $value->name,
            $value instanceof DateTimeInterface => $value->format(DATE_ATOM),
            $value instanceof Model => ['_model' => $value->getMorphClass(), 'id' => $value->getKey()],
            $value instanceof Throwable => $this->exception($value),
            $value instanceof Arrayable => $this->walkArray($value->toArray(), $depth),
            $value instanceof JsonSerializable => $this->walk($value->jsonSerialize(), $depth + 1),
            $value instanceof Stringable => (string) $value,
            is_object($value) => '['.$value::class.']',
            default => '['.get_debug_type($value).']',
        };
    }

    /**
     * @param  array<array-key, mixed>  $values
     * @return array<array-key, mixed>
     */
    private function walkArray(array $values, int $depth): array
    {
        $result = [];

        foreach ($values as $key => $item) {
            $result[$key] = is_string($key) && $this->isSensitive($key)
                ? self::REDACTED
                : $this->walk($item, $depth + 1);
        }

        return $result;
    }

    private function isSensitive(string $key): bool
    {
        $key = strtolower($key);

        foreach ((array) config('activity-log.redact', []) as $fragment) {
            if ($fragment !== '' && str_contains($key, strtolower($fragment))) {
                return true;
            }
        }

        return false;
    }
}
