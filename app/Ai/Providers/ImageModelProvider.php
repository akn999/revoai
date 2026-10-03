<?php

namespace App\Ai\Providers;

use App\Ai\Dto\AnalysisResult;
use App\Ai\Dto\ImageEditRequest;
use App\Ai\Dto\ImageJobStatus;
use App\Ai\Dto\ImageResult;
use App\Ai\Dto\ModerationVerdict;

interface ImageModelProvider
{
    /**
     * Queue an edit and return the provider request id.
     */
    public function submit(ImageEditRequest $request): string;

    public function status(string $modelId, string $requestId): ImageJobStatus;

    public function result(string $modelId, string $requestId): ImageResult;

    public function analyze(string $modelId, string $imageUrl, string $prompt): AnalysisResult;

    /**
     * @param  array<string, string>  $categories  Active category key => instruction text.
     */
    public function moderate(string $modelId, string $imageUrl, array $categories): ModerationVerdict;
}
