<?php

namespace App\Ai;

use App\Models\AiModel;
use App\Models\UsageLedger;

class UsageRecorder
{
    public function record(
        AiContext $context,
        AiModel $model,
        string $status,
        int $latencyMs,
        int $inputTokens = 0,
        int $outputTokens = 0,
        int $images = 0,
        ?string $requestId = null,
    ): UsageLedger {
        return UsageLedger::query()->create([
            'merchant_id' => $context->merchantId,
            'salla_user_id' => $context->sallaUserId,
            'feature' => $context->feature,
            'action' => $context->action,
            'provider' => $model->provider,
            'ai_model_id' => $model->id,
            'provider_model_id' => $model->provider_model_id,
            'provider_request_id' => $requestId,
            'input_tokens' => $inputTokens,
            'output_tokens' => $outputTokens,
            'images' => $images,
            'provider_cost_usd' => $status === 'ok' ? $model->costUsd($inputTokens, $outputTokens, $images) : 0,
            'credits_charged' => $status === 'ok' ? $context->credits : 0,
            'internal' => $context->internal,
            'status' => $status,
            'latency_ms' => $latencyMs,
            'reference_type' => $context->referenceType,
            'reference_id' => $context->referenceId,
        ]);
    }
}
