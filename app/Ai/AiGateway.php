<?php

namespace App\Ai;

use App\Ai\Dto\AnalysisResult;
use App\Ai\Dto\ImageEditRequest;
use App\Ai\Dto\ImageJobStatus;
use App\Ai\Dto\ImageResult;
use App\Ai\Dto\ModerationVerdict;
use App\Ai\Dto\TextRequest;
use App\Ai\Dto\TextResponse;
use App\Ai\Exceptions\AiUnavailable;
use App\Ai\Exceptions\InvalidStructuredOutput;
use App\Ai\Providers\ImageModelProvider;
use App\Ai\Providers\TextModelProvider;
use App\Logging\Activity;
use App\Models\AiModel;
use Throwable;

/**
 * The single door to every AI provider. It writes one usage-ledger row per provider call,
 * charged or not, and turns provider failures into a merchant-safe message (FR-AI-005/006).
 * Credit reservation, moderation and plan checks happen in the feature services that call it.
 */
class AiGateway
{
    public function __construct(
        private TextModelProvider $text,
        private ImageModelProvider $images,
        private UsageRecorder $usage,
    ) {}

    public function converse(AiContext $context, AiModel $model, TextRequest $request): TextResponse
    {
        $startedAt = hrtime(true);

        try {
            $response = $this->text->converse($request);
        } catch (Throwable $exception) {
            $this->failed($context, $model, $startedAt, $exception);

            throw AiUnavailable::busy($exception);
        }

        $this->usage->record($context, $model, 'ok', $this->elapsed($startedAt), $response->inputTokens, $response->outputTokens, 0, $response->requestId);

        return $response;
    }

    /**
     * Structured output: Converse tool use when the model supports it, otherwise strict JSON.
     * A malformed answer is retried once, then fails cleanly (FR-AI-004).
     *
     * @param  array<string, mixed>  $schema  JSON Schema of the expected object.
     * @return array<string, mixed>
     */
    public function structured(AiContext $context, AiModel $model, TextRequest $request, array $schema, string $toolName = 'submit_result'): array
    {
        $useTool = $model->can('tool_use');
        $attempts = 2;

        $prepared = $useTool
            ? new TextRequest($request->modelId, $request->system, $request->messages, ['name' => $toolName, 'description' => 'Return the result.', 'schema' => $schema], $request->maxTokens, $request->temperature)
            : new TextRequest(
                $request->modelId,
                $request->system."\n\nRespond with a single JSON object only, matching this JSON Schema: ".json_encode($schema, JSON_UNESCAPED_UNICODE),
                $request->messages,
                null,
                $request->maxTokens,
                $request->temperature,
            );

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            $response = $this->converse($context, $model, $prepared);
            $data = $useTool ? $response->toolInput : $this->decodeJson($response->text);

            if (is_array($data)) {
                return $data;
            }
        }

        Activity::channel('system')->bySystem()->forMerchant($context->merchantId)
            ->warning('ai.invalid_structured_output', "Model {$model->provider_model_id} returned malformed structured output");

        throw new InvalidStructuredOutput('The AI service returned an unreadable answer');
    }

    public function analyzeImage(AiContext $context, AiModel $model, string $imageUrl, string $prompt): AnalysisResult
    {
        $startedAt = hrtime(true);

        try {
            $result = $this->images->analyze($model->provider_model_id, $imageUrl, $prompt);
        } catch (Throwable $exception) {
            $this->failed($context, $model, $startedAt, $exception);

            throw AiUnavailable::busy($exception);
        }

        $this->usage->record($context, $model, 'ok', $this->elapsed($startedAt), 0, 0, 1, $result->requestId);

        return $result;
    }

    /**
     * @param  array<string, string>  $categories
     */
    public function moderateImage(AiContext $context, AiModel $model, string $imageUrl, array $categories): ModerationVerdict
    {
        $startedAt = hrtime(true);

        try {
            $verdict = $this->images->moderate($model->provider_model_id, $imageUrl, $categories);
        } catch (Throwable $exception) {
            $this->failed($context, $model, $startedAt, $exception);

            throw AiUnavailable::busy($exception);
        }

        $this->usage->record($context, $model, $verdict->blocked ? 'blocked' : 'ok', $this->elapsed($startedAt), 0, 0, 1, $verdict->requestId);

        return $verdict;
    }

    /**
     * Queue an image edit. The usage row is written when the result is fetched, so it carries the real image count.
     */
    public function submitImageEdit(AiContext $context, AiModel $model, ImageEditRequest $request): string
    {
        $startedAt = hrtime(true);

        try {
            return $this->images->submit($request);
        } catch (Throwable $exception) {
            $this->failed($context, $model, $startedAt, $exception);

            throw AiUnavailable::busy($exception);
        }
    }

    public function imageStatus(AiModel $model, string $requestId): ImageJobStatus
    {
        try {
            return $this->images->status($model->provider_model_id, $requestId);
        } catch (Throwable $exception) {
            throw AiUnavailable::busy($exception);
        }
    }

    public function imageResult(AiContext $context, AiModel $model, string $requestId, int $startedAtMs = 0): ImageResult
    {
        $startedAt = hrtime(true);

        try {
            $result = $this->images->result($model->provider_model_id, $requestId);
        } catch (Throwable $exception) {
            $this->failed($context, $model, $startedAt, $exception);

            throw AiUnavailable::busy($exception);
        }

        $this->usage->record($context, $model, 'ok', $startedAtMs ?: $this->elapsed($startedAt), 0, 0, count($result->urls), $requestId);

        return $result;
    }

    /**
     * Record a provider job that finished with an error, so it still appears in the ledger.
     */
    public function recordFailure(AiContext $context, AiModel $model, ?string $requestId = null): void
    {
        $this->usage->record($context, $model, 'error', 0, 0, 0, 0, $requestId);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function decodeJson(string $text): ?array
    {
        $decoded = json_decode(trim($text), true);

        return is_array($decoded) ? $decoded : null;
    }

    private function failed(AiContext $context, AiModel $model, int $startedAt, Throwable $exception): void
    {
        $this->usage->record($context, $model, 'error', $this->elapsed($startedAt));

        Activity::channel('system')->bySystem()->forMerchant($context->merchantId)->withException($exception)
            ->warning('ai.provider_failed', "{$model->provider} call failed");
    }

    private function elapsed(int $startedAt): int
    {
        return (int) ((hrtime(true) - $startedAt) / 1_000_000);
    }
}
