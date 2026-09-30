<?php

namespace App\Http\Controllers\Webhooks;

use App\Enums\AppEventStatus;
use App\Http\Controllers\Controller;
use App\Jobs\ProcessAppEvent;
use App\Logging\Activity;
use App\Models\AppEvent;
use App\Salla\PayloadVault;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SallaWebhookController extends Controller
{
    public function __invoke(Request $request, PayloadVault $vault): JsonResponse
    {
        $body = $request->json()->all();
        $merchantId = data_get($body, 'merchant');
        $eventName = data_get($body, 'event');

        if (! is_numeric($merchantId) || ! is_string($eventName) || $eventName === '') {
            Activity::channel('webhook')->bySystem()->with(['event' => is_string($eventName) ? $eventName : null])
                ->warning('salla.payload_malformed', 'Rejected webhook with a malformed payload');

            return response()->json(['message' => 'Malformed payload'], 422);
        }

        $event = AppEvent::createOrFirst(
            ['payload_hash' => hash('sha256', $request->getContent())],
            [
                'merchant_id' => (int) $merchantId,
                'event' => $eventName,
                'payload' => $vault->seal($body),
                'event_created_at' => data_get($body, 'created_at') ?? now(),
                'status' => AppEventStatus::Received,
            ],
        );

        $activity = Activity::channel('webhook')->bySystem()->on($event)->forMerchant($event->merchant_id)
            ->with(['event' => $eventName, 'app_event_id' => $event->id]);

        if ($event->wasRecentlyCreated) {
            $activity->info('salla.received', "Received {$eventName}");

            ProcessAppEvent::dispatch($event->id, $event->merchant_id)
                ->onQueue(config('salla.queue'));
        } else {
            $activity->info('salla.duplicate', "Duplicate delivery of {$eventName} ignored");
        }

        return response()->json(['received' => true]);
    }
}
