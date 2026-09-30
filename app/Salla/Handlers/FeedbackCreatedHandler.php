<?php

namespace App\Salla\Handlers;

use App\Models\AppEvent;
use App\Salla\HandlerResult;
use App\Services\MerchantService;

class FeedbackCreatedHandler implements AppEventHandler
{
    public function __construct(private MerchantService $merchants) {}

    public function handle(AppEvent $event): HandlerResult
    {
        $merchant = $this->merchants->resolve($event);

        $merchant->feedback()->firstOrCreate(
            ['app_event_id' => $event->id],
            [
                'rating' => (int) $event->data('rating', 0),
                'rated_by' => $event->data('rated_by'),
                'comment' => $event->data('comment'),
            ],
        );

        return HandlerResult::Processed;
    }
}
