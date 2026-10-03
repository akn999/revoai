<?php

namespace App\Products;

use App\Ai\Middleware\LimitAiJobs;
use App\Models\BulkJob;
use App\Models\ContentGeneration;
use App\Support\CurrentMerchant;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class RunContentGeneration implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public int $merchantId, public int $generationId) {}

    /**
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [new LimitAiJobs($this->merchantId)];
    }

    public function handle(ContentGenerationService $service, CurrentMerchant $context): void
    {
        $context->set($this->merchantId);
        $generation = ContentGeneration::query()->find($this->generationId);

        if ($generation) {
            $service->process($generation);

            if ($generation->bulk_job_id && ($job = BulkJob::query()->find($generation->bulk_job_id))) {
                app(BulkContentService::class)->refresh($job);
            }
        }
    }
}
