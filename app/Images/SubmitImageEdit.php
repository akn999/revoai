<?php

namespace App\Images;

use App\Ai\Middleware\LimitAiJobs;
use App\Models\ImageGeneration;
use App\Support\CurrentMerchant;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SubmitImageEdit implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public int $merchantId, public int $generationId) {}

    /**
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [new LimitAiJobs($this->merchantId, 'fal')];
    }

    public function handle(ImageEditService $service, CurrentMerchant $context): void
    {
        $context->set($this->merchantId);
        $generation = ImageGeneration::query()->find($this->generationId);

        if ($generation && $service->submit($generation)->status === ImageGeneration::SUBMITTED) {
            PollImageEdit::dispatch($this->merchantId, $this->generationId)->delay(now()->addSeconds(5))->onQueue(config('salla.queue'));
        }
    }
}
