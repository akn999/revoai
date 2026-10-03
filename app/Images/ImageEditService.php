<?php

namespace App\Images;

use App\Ai\AiContext;
use App\Ai\AiGateway;
use App\Ai\Dto\ImageEditRequest;
use App\Ai\Dto\ImageJobStatus;
use App\Ai\Exceptions\AiUnavailable;
use App\Ai\ModelResolver;
use App\Ai\PriceResolver;
use App\Ai\SallaCdn;
use App\Billing\Exceptions\InsufficientCredits;
use App\Billing\WalletService;
use App\Images\Exceptions\InvalidImage;
use App\Models\AiModel;
use App\Models\CreditReservation;
use App\Models\GeneratedImage;
use App\Models\ImageAnalysis;
use App\Models\ImageGeneration;
use App\Models\Merchant;
use App\Models\Preset;
use App\Models\Product;
use App\Models\ProductImage;
use App\Moderation\ModerationBlocked;
use App\Moderation\ModerationService;
use App\Products\Exceptions\PlanLocked;
use App\Products\Exceptions\ReviewRejected;
use App\Products\ProductContextService;
use App\Settings\PresetParameterValidator;
use App\Settings\PromptService;
use App\Settings\SettingsException;
use App\Settings\SettingsService;
use App\Settings\StoreContextCompiler;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * The image-edit flow (FR-IMG-*): validate, analyze (cached), compose the prompt on the server,
 * moderate, reserve credits, submit to fal, poll, moderate the outputs, store drafts, capture.
 * Anything that goes wrong after the reservation releases it.
 */
class ImageEditService
{
    public function __construct(
        private AiGateway $gateway,
        private ModelResolver $models,
        private PriceResolver $prices,
        private WalletService $wallets,
        private ModerationService $moderation,
        private SettingsService $settings,
        private PromptService $prompts,
        private StoreContextCompiler $storeContext,
        private PresetParameterValidator $parameters,
        private ProductContextService $contexts,
        private ImageSanitizer $sanitizer,
        private ImageStore $store,
    ) {}

    /**
     * Credits for one edit with the given variant count: price per generated image (FR-IMG-010).
     *
     * @return array{credits: int, source: string, unit: int, model_id: int|null}
     */
    public function quote(Merchant $merchant, ?Preset $preset = null, int $variants = 1): array
    {
        $model = $this->modelFor($merchant, $preset);
        $price = $this->prices->resolve('image_edit', $model, $preset);

        return ['credits' => $price['credits'] * $variants, 'source' => $price['source'], 'unit' => $price['credits'], 'model_id' => $model?->id];
    }

    /**
     * @param  array{preset_id?: int|null, instruction?: string|null, variants?: int|null, params?: array<string, mixed>|null, dispatch?: bool, parent_generated_image_id?: int|null, source_url?: string|null, source_key?: string|null}  $options
     *
     * @throws PlanLocked
     * @throws ReviewRejected
     * @throws SettingsException
     * @throws ModerationBlocked
     * @throws InsufficientCredits
     */
    public function request(Merchant $merchant, ?Product $product, ?ProductImage $source, ?int $sallaUserId, array $options = []): ImageGeneration
    {
        if (! $merchant->planAllows('image_edit')) {
            throw new PlanLocked('image_edit');
        }

        $settings = $this->settings->settingsFor($merchant->merchant_id);
        $preset = isset($options['preset_id']) ? Preset::query()->availableTo($merchant->merchant_id)->where('active', true)->find($options['preset_id']) : null;

        if (isset($options['preset_id']) && ! $preset) {
            throw new ReviewRejected('Preset not found.', ['preset_id' => ['Preset not found.']]);
        }

        $instruction = trim((string) ($options['instruction'] ?? ''));

        if ($preset === null && $instruction === '') {
            throw new ReviewRejected('Choose a preset or describe the edit.', ['instruction' => ['Choose a preset or describe the edit.']]);
        }

        $variants = (int) ($options['variants'] ?? $settings->default_variants);

        if ($variants < 1 || $variants > (int) config('revo.images.max_variants')) {
            throw new ReviewRejected('Invalid number of variants.', ['variants' => ['Choose 1 to '.config('revo.images.max_variants').'.']]);
        }

        [$sourceUrl, $sourceKey] = $this->source($source, $options);
        $model = $this->modelFor($merchant, $preset) ?? throw new ReviewRejected('No image model is available right now.');
        $params = [...($settings->image_defaults ?? []), ...($preset->params ?? []), ...($options['params'] ?? [])];
        $this->parameters->validate($model->param_schema, $params);

        $context = new AiContext($merchant->merchant_id, 'image_edit', 'image_edit', $sallaUserId);
        $this->moderation->checkText($context, $instruction, 'image_instruction');

        $analysis = $this->analysis($merchant, $context, $sourceUrl, $sourceKey);
        $composed = $this->compose($merchant, $preset, $instruction, $analysis);
        $price = $this->prices->resolve('image_edit', $model, $preset);
        $reservation = $this->wallets->reserve($merchant->merchant_id, $price['credits'] * $variants, 'image_edit', $price['source']);

        $generation = ImageGeneration::query()->create([
            'merchant_id' => $merchant->merchant_id, 'product_id' => $product?->id, 'source_image_id' => $source?->id,
            'parent_generated_image_id' => $options['parent_generated_image_id'] ?? null,
            'source_url' => $sourceUrl, 'source_key' => $sourceKey, 'preset_id' => $preset?->id, 'ai_model_id' => $model->id,
            'instruction' => $instruction ?: null, 'composed_prompt' => $composed, 'params' => $params ?: null, 'variants' => $variants,
            'status' => ImageGeneration::QUEUED, 'credits' => $reservation->amount, 'reservation_id' => $reservation->id, 'salla_user_id' => $sallaUserId,
        ]);

        $reservation->forceFill(['reference_type' => ImageGeneration::class, 'reference_id' => (string) $generation->id])->save();

        if ($options['dispatch'] ?? true) {
            SubmitImageEdit::dispatch($merchant->merchant_id, $generation->id)->onQueue(config('salla.queue'));
        }

        return $generation;
    }

    /**
     * Send the job to fal. Failure releases the credits.
     */
    public function submit(ImageGeneration $generation): ImageGeneration
    {
        if ($generation->status !== ImageGeneration::QUEUED) {
            return $generation;
        }

        try {
            $model = AiModel::query()->findOrFail($generation->ai_model_id);
            $context = $this->contextFor($generation);
            $id = $this->gateway->submitImageEdit($context->charging($generation->credits), $model, new ImageEditRequest(
                $model->provider_model_id, $generation->source_url, (string) $generation->composed_prompt, (array) $generation->params, $generation->variants,
            ));
        } catch (Throwable $exception) {
            return $this->fail($generation, ImageGeneration::FAILED, $exception instanceof AiUnavailable ? $exception->getMessage() : 'The image service could not start this edit.');
        }

        $generation->forceFill(['status' => ImageGeneration::SUBMITTED, 'provider_request_id' => $id, 'submitted_at' => now()])->save();

        return $generation;
    }

    /**
     * One poll step. Returns true while the job is still in flight and should be polled again.
     */
    public function poll(ImageGeneration $generation): bool
    {
        if ($generation->status !== ImageGeneration::SUBMITTED) {
            return false;
        }

        if ($generation->submitted_at->lt(now()->subMinutes((int) config('revo.limits.image_timeout_minutes')))) {
            $this->gateway->recordFailure($this->contextFor($generation), AiModel::query()->findOrFail($generation->ai_model_id), $generation->provider_request_id);
            $this->fail($generation, ImageGeneration::TIMED_OUT, 'The edit took too long and was canceled. Your credits were returned.');

            return false;
        }

        $model = AiModel::query()->findOrFail($generation->ai_model_id);

        try {
            $status = $this->gateway->imageStatus($model, (string) $generation->provider_request_id);
        } catch (AiUnavailable) {
            return true;
        }

        if (! $status->isFinished()) {
            return true;
        }

        if ($status->state === ImageJobStatus::FAILED) {
            $this->gateway->recordFailure($this->contextFor($generation), $model, $generation->provider_request_id);
            $this->fail($generation, ImageGeneration::FAILED, 'The image service could not complete this edit.');

            return false;
        }

        $this->complete($generation, $model);

        return false;
    }

    private function complete(ImageGeneration $generation, AiModel $model): void
    {
        $context = $this->contextFor($generation)->charging($generation->credits);

        try {
            $result = $this->gateway->imageResult($context, $model, (string) $generation->provider_request_id);
            $stored = [];
            $blocked = null;

            foreach ($result->urls as $url) {
                try {
                    $this->moderation->checkImage($context->for('image_edit', 'image_moderation'), $url, 'image_output', (string) $generation->id);
                } catch (ModerationBlocked $exception) {
                    $blocked = $exception;

                    continue;
                }

                $stored[] = $this->download($generation, $url);
            }
        } catch (Throwable $exception) {
            $this->fail($generation, ImageGeneration::FAILED, 'The edited image could not be checked or saved. Your credits were returned.');
            report($exception);

            return;
        }

        if ($stored === []) {
            $this->fail($generation, $blocked ? ImageGeneration::BLOCKED : ImageGeneration::FAILED, $blocked?->getMessage() ?? 'No image was returned.');

            return;
        }

        $generation->forceFill(['status' => ImageGeneration::COMPLETED])->save();

        if ($reservation = CreditReservation::query()->find($generation->reservation_id)) {
            $this->wallets->capture($reservation);
        }
    }

    /**
     * Download an output from the provider (host allow-listed), clean it and keep it as a draft.
     *
     * @throws InvalidImage
     */
    private function download(ImageGeneration $generation, string $url): GeneratedImage
    {
        $response = Http::timeout(60)->get($url)->throw();
        $clean = $this->sanitizer->clean($response->body());
        $file = $this->store->put($generation->merchant_id, $clean['contents'], $clean['extension']);

        return GeneratedImage::query()->create([
            'merchant_id' => $generation->merchant_id, 'generation_id' => $generation->id, 'product_id' => $generation->product_id,
            'parent_id' => $generation->parent_generated_image_id, 'source' => 'generated', ...$file,
            'mime' => $clean['mime'], 'bytes' => strlen($clean['contents']), 'status' => GeneratedImage::DRAFT,
            'expires_at' => now()->addDays((int) config('revo.limits.image_days')),
        ]);
    }

    private function fail(ImageGeneration $generation, string $status, string $message): ImageGeneration
    {
        if ($reservation = CreditReservation::query()->find($generation->reservation_id)) {
            $this->wallets->release($reservation, $message);
        }

        $generation->forceFill(['status' => $status, 'error' => $message, 'credits' => 0])->save();

        return $generation;
    }

    /**
     * Source image: a Salla-hosted product image (CDN allow-list) or an earlier generated image.
     *
     * @param  array<string, mixed>  $options
     * @return array{0: string, 1: string}
     */
    private function source(?ProductImage $source, array $options): array
    {
        if ($source) {
            $url = SallaCdn::assertAllowed($source->url);

            return [$url, $this->contexts->sourceKey($source)];
        }

        $url = (string) ($options['source_url'] ?? '');

        if ($url === '') {
            throw new ReviewRejected('Choose a source image.', ['source' => ['Choose a source image.']]);
        }

        return [$url, (string) ($options['source_key'] ?? 'generated:'.md5($url))];
    }

    private function analysis(Merchant $merchant, AiContext $context, string $url, string $key): ?string
    {
        $cached = ImageAnalysis::query()->where('source_key', $key)->first();

        if ($cached) {
            return $cached->analysis;
        }

        $model = $this->models->defaultFor('image_analysis');

        if (! $model) {
            return null;
        }

        try {
            $result = $this->gateway->analyzeImage($context->for('image_edit', 'image_analysis'), $model, $url, $this->prompts->effective($merchant->merchant_id, 'image_general'));
        } catch (AiUnavailable) {
            return null;
        }

        ImageAnalysis::query()->create(['merchant_id' => $merchant->merchant_id, 'source_key' => $key, 'ai_model_id' => $model->id, 'analysis' => $result->description]);

        return $result->description;
    }

    private function compose(Merchant $merchant, ?Preset $preset, string $instruction, ?string $analysis): string
    {
        $parts = [$this->prompts->effective($merchant->merchant_id, 'image_editing')];

        if ($preset) {
            $parts[] = $preset->prompt;
        }

        if ($instruction !== '') {
            $parts[] = 'Merchant instruction: '.$instruction;
        }

        if ($analysis) {
            $parts[] = 'Source image description: '.$analysis;
        }

        $context = $this->storeContext->compile($merchant->merchant_id, 'images', $merchant->default_language);

        if ($context !== '') {
            $parts[] = "Store context:\n".$context;
        }

        $parts[] = $this->moderation->platformRules();

        return implode("\n\n", array_filter($parts));
    }

    private function modelFor(Merchant $merchant, ?Preset $preset): ?AiModel
    {
        $selected = $preset->ai_model_id ?? ($this->settings->settingsFor($merchant->merchant_id)->models['image_edit'] ?? null);

        return $this->models->resolve('image_edit', $selected);
    }

    private function contextFor(ImageGeneration $generation): AiContext
    {
        return new AiContext($generation->merchant_id, 'image_edit', 'image_edit', $generation->salla_user_id, true, 0, ImageGeneration::class, (string) $generation->id);
    }
}
