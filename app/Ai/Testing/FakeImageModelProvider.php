<?php

namespace App\Ai\Testing;

use App\Ai\Dto\AnalysisResult;
use App\Ai\Dto\ImageEditRequest;
use App\Ai\Dto\ImageJobStatus;
use App\Ai\Dto\ImageResult;
use App\Ai\Dto\ModerationVerdict;
use App\Ai\Providers\ImageModelProvider;
use Closure;
use Throwable;

/**
 * Scripted image provider used by every test: no network, full recording.
 */
class FakeImageModelProvider implements ImageModelProvider
{
    /** @var array<int, ImageEditRequest> */
    public array $submitted = [];

    /** @var array<int, array{model: string, image: string, prompt: string}> */
    public array $analyses = [];

    /** @var array<int, string> */
    public array $moderated = [];

    /** @var array<string, int> */
    private array $variants = [];

    /** @var array<int, ImageJobStatus> */
    private array $statuses = [];

    public ?Throwable $submitFails = null;

    public ?Throwable $moderationFails = null;

    public ?Throwable $analysisFails = null;

    /** @var Closure|null (string, array): ?string  returns a blocked category key */
    public ?Closure $moderationRule = null;

    /**
     * Statuses returned by successive status() calls; the last one repeats.
     */
    public function statuses(ImageJobStatus ...$statuses): self
    {
        $this->statuses = array_values($statuses);

        return $this;
    }

    public function submit(ImageEditRequest $request): string
    {
        if ($this->submitFails) {
            throw $this->submitFails;
        }

        $this->submitted[] = $request;
        $id = 'fake-image-'.count($this->submitted);
        $this->variants[$id] = $request->variants;

        return $id;
    }

    public function status(string $modelId, string $requestId): ImageJobStatus
    {
        if (count($this->statuses) > 1) {
            return array_shift($this->statuses);
        }

        return $this->statuses[0] ?? new ImageJobStatus(ImageJobStatus::COMPLETED);
    }

    public function result(string $modelId, string $requestId): ImageResult
    {
        $urls = [];

        for ($i = 1; $i <= ($this->variants[$requestId] ?? 1); $i++) {
            $urls[] = "https://v3.fal.media/files/{$requestId}-{$i}.png";
        }

        return new ImageResult($urls, $requestId);
    }

    public function analyze(string $modelId, string $imageUrl, string $prompt): AnalysisResult
    {
        if ($this->analysisFails) {
            throw $this->analysisFails;
        }

        $this->analyses[] = ['model' => $modelId, 'image' => $imageUrl, 'prompt' => $prompt];

        return new AnalysisResult('A product photographed on a plain background.', 'fake-analysis-'.count($this->analyses));
    }

    public function moderate(string $modelId, string $imageUrl, array $categories): ModerationVerdict
    {
        if ($this->moderationFails) {
            throw $this->moderationFails;
        }

        $this->moderated[] = $imageUrl;
        $category = $this->moderationRule ? ($this->moderationRule)($imageUrl, $categories) : null;

        return $category ? ModerationVerdict::blocked($category) : ModerationVerdict::allowed();
    }
}
