<?php

namespace App\Http\Controllers\Webhooks;

use App\Enums\AppEventStatus;
use App\Http\Controllers\Controller;
use App\Jobs\ProcessAppEvent;
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

        if ($event->wasRecentlyCreated) {
            ProcessAppEvent::dispatch($event->id, $event->merchant_id)
                ->onQueue(config('salla.queue'));
        }

        return response()->json(['received' => true]);
    }
}
