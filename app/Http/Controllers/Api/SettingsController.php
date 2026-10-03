<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\ResolvesMerchant;
use App\Http\Controllers\Controller;
use App\Models\Preset;
use App\Settings\SettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class SettingsController extends Controller
{
    use ResolvesMerchant;

    public function __construct(private SettingsService $settings) {}

    public function show(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->settings->snapshot($this->merchant($request))]);
    }

    public function updateGeneral(Request $request): JsonResponse
    {
        $this->settings->updateGeneral($this->merchant($request), $request->all(), $this->sallaUserId($request));

        return $this->show($request);
    }

    public function savePrompt(Request $request, string $key): JsonResponse
    {
        $data = $request->validate(['body' => ['nullable', 'string', 'max:10000']]);
        $this->settings->savePrompt($this->merchant($request), $key, $data['body'] ?? null, $this->sallaUserId($request));

        return $this->show($request);
    }

    public function saveContext(Request $request): JsonResponse
    {
        $this->settings->saveStoreContext($this->merchant($request), $request->all(), $this->sallaUserId($request));

        return $this->show($request);
    }

    public function createPreset(Request $request): JsonResponse
    {
        $preset = $this->settings->savePreset($this->merchant($request), $request->all(), $this->sallaUserId($request));

        return response()->json(['data' => $preset->only(['id', 'name_ar', 'name_en', 'prompt', 'ai_model_id', 'params'])], 201);
    }

    public function updatePreset(Request $request, int $preset): JsonResponse
    {
        $record = Preset::query()->where('merchant_id', $this->merchant($request)->merchant_id)->findOrFail($preset);
        $updated = $this->settings->savePreset($this->merchant($request), $request->all(), $this->sallaUserId($request), $record);

        return response()->json(['data' => $updated->only(['id', 'name_ar', 'name_en', 'prompt', 'ai_model_id', 'params'])]);
    }

    public function deletePreset(Request $request, int $preset): Response
    {
        $record = Preset::query()->where('merchant_id', $this->merchant($request)->merchant_id)->findOrFail($preset);
        $this->settings->deletePreset($this->merchant($request), $record, $this->sallaUserId($request));

        return response()->noContent();
    }

    public function showDraft(Request $request, string $formKey): JsonResponse
    {
        $draft = $this->settings->draft($this->merchant($request)->merchant_id, $this->sallaUserId($request), $formKey);

        return response()->json(['data' => $draft?->payload]);
    }

    public function saveDraft(Request $request, string $formKey): JsonResponse
    {
        $data = $request->validate(['payload' => ['required', 'array']]);
        $this->settings->saveDraft($this->merchant($request)->merchant_id, $this->sallaUserId($request), $formKey, $data['payload']);

        return response()->json(['data' => ['saved' => true]]);
    }

    public function discardDraft(Request $request, string $formKey): Response
    {
        $this->settings->discardDraft($this->merchant($request)->merchant_id, $this->sallaUserId($request), $formKey);

        return response()->noContent();
    }
}
