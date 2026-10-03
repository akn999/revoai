<?php

namespace App\Ai\Providers;

use App\Ai\Dto\AnalysisResult;
use App\Ai\Dto\ImageEditRequest;
use App\Ai\Dto\ImageJobStatus;
use App\Ai\Dto\ImageResult;
use App\Ai\Dto\ModerationVerdict;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * fal.ai: edits go through the queue API (submit, status, result); analysis and
 * moderation are short synchronous calls. The key comes from environment configuration only.
 */
class FalProvider implements ImageModelProvider
{
    public function submit(ImageEditRequest $request): string
    {
        $body = [
            ...$request->parameters,
            'image_url' => $request->imageUrl,
            'prompt' => $request->prompt,
            'num_images' => $request->variants,
        ];

        $url = rtrim((string) config('revo.ai.fal.queue_url'), '/').'/'.$request->modelId
            .($request->callbackUrl ? '?fal_webhook='.urlencode($request->callbackUrl) : '');

        $response = $this->http()->post($url, $body)->throw();

        return (string) ($response->json('request_id') ?? throw new RuntimeException('fal.ai returned no request id'));
    }

    public function status(string $modelId, string $requestId): ImageJobStatus
    {
        $response = $this->http()->get($this->queueUrl($modelId, $requestId).'/status')->throw();

        return match (strtoupper((string) $response->json('status'))) {
            'COMPLETED' => $response->json('error') ? new ImageJobStatus(ImageJobStatus::FAILED, (string) $response->json('error')) : new ImageJobStatus(ImageJobStatus::COMPLETED),
            'IN_PROGRESS' => new ImageJobStatus(ImageJobStatus::RUNNING),
            'FAILED', 'ERROR' => new ImageJobStatus(ImageJobStatus::FAILED, (string) $response->json('error')),
            default => new ImageJobStatus(ImageJobStatus::QUEUED),
        };
    }

    public function result(string $modelId, string $requestId): ImageResult
    {
        $response = $this->http()->get($this->queueUrl($modelId, $requestId))->throw();

        $urls = array_values(array_filter(array_map(
            fn (array $image): ?string => $image['url'] ?? null,
            (array) $response->json('images', []),
        )));

        return new ImageResult($urls, $requestId);
    }

    public function analyze(string $modelId, string $imageUrl, string $prompt): AnalysisResult
    {
        $response = $this->http()->post('https://fal.run/'.$modelId, ['image_url' => $imageUrl, 'prompt' => $prompt])->throw();

        return new AnalysisResult((string) ($response->json('output') ?? $response->json('description') ?? ''), $response->header('x-fal-request-id') ?: null);
    }

    public function moderate(string $modelId, string $imageUrl, array $categories): ModerationVerdict
    {
        $response = $this->http()->post('https://fal.run/'.$modelId, [
            'image_url' => $imageUrl,
            'categories' => array_keys($categories),
        ])->throw();

        $category = $response->json('blocked_category');

        return $response->json('blocked') === true || is_string($category)
            ? new ModerationVerdict(true, is_string($category) ? $category : null, $response->header('x-fal-request-id') ?: null)
            : new ModerationVerdict(false, null, $response->header('x-fal-request-id') ?: null);
    }

    private function queueUrl(string $modelId, string $requestId): string
    {
        return rtrim((string) config('revo.ai.fal.queue_url'), '/').'/'.$modelId.'/requests/'.$requestId;
    }

    private function http(): PendingRequest
    {
        return Http::withHeaders(['Authorization' => 'Key '.config('revo.ai.fal.key')])
            ->acceptJson()
            ->timeout(60)
            ->retry(2, 500, when: fn (\Throwable $e): bool => $e instanceof ConnectionException
                || ($e instanceof RequestException && ($e->response->serverError() || $e->response->status() === 429)), throw: false);
    }
}
