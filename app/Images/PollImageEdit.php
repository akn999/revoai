<?php

namespace App\Images;

use App\Models\ImageGeneration;
use App\Support\CurrentMerchant;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class PollImageEdit implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public int $merchantId, public int $generationId) {}

    public function handle(ImageEditService $service, CurrentMerchant $context): void
    {
        $context->set($this->merchantId);
        $generation = ImageGeneration::query()->find($this->generationId);

        if ($generation && $service->poll($generation)) {
            self::dispatch($this->merchantId, $this->generationId)->delay(now()->addSeconds(5))->onQueue(config('salla.queue'));
        }
    }
}
