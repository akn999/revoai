<?php

namespace App\Products;

use App\Ai\AiContext;
use App\Ai\AiGateway;
use App\Ai\Dto\TextRequest;
use App\Ai\Exceptions\AiUnavailable;
use App\Ai\ModelResolver;
use App\Ai\PriceResolver;
use App\Billing\Exceptions\InsufficientCredits;
use App\Billing\WalletService;
use App\Models\AiModel;
use App\Models\ContentGeneration;
use App\Models\CreditReservation;
use App\Models\Merchant;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductImageAlt;
use App\Moderation\ModerationBlocked;
use App\Moderation\ModerationService;
use App\Moderation\ModerationUnavailable;
use App\Products\Exceptions\PlanLocked;
use App\Products\Exceptions\ReviewRejected;
use App\Settings\SettingsService;
use Illuminate\Support\Facades\Date;
use Throwable;

/**
 * Product content generation (FR-PRD-003 to 018). The request step reserves credits and queues the
 * work; the process step runs on a queue worker and either captures the credits (draft ready) or
 * releases them (provider error, moderation block, unreadable output).
 */
class ContentGenerationService
{
    public function __construct(
        private AiGateway $gateway,
        private ModelResolver $models,
        private PriceResolver $prices,
        private WalletService $wallets,
        private ModerationService $moderation,
        private SettingsService $settings,
        private ProductContextService $contexts,
        private ContentPromptComposer $composer,
        private HtmlSanitizer $sanitizer,
        private SlugGenerator $slugs,
        private ContentReviewService $review,
    ) {}

    /**
     * Price shown to the merchant before running: the model's price per product-language (FR-PRD-003).
     *
     * @return array{credits: int, source: string, model_id: int|null}
     */
    public function quote(Merchant $merchant, string $kind = 'full'): array
    {
        $model = $this->modelFor($merchant, $kind);
        $price = $this->prices->resolve($this->action($kind), $model);

        return [...$price, 'model_id' => $model?->id];
    }

    /**
     * @param  array<int, string>  $fields
     * @param  array{keywords?: string|null, instruction?: string|null, kind?: string, bulk_job_id?: int|null, reservation?: CreditReservation|null, dispatch?: bool}  $options
     *
     * @throws PlanLocked
     * @throws ReviewRejected
     * @throws InsufficientCredits
     */
    public function request(Merchant $merchant, Product $product, string $language, array $fields, ?int $sallaUserId = null, array $options = []): ContentGeneration
    {
        $kind = $options['kind'] ?? 'full';
        $feature = $kind === 'field' ? 'field_regeneration' : 'product_content';

        if (! $merchant->planAllows('product_content')) {
            throw new PlanLocked($feature);
        }

        $this->validateRequest($merchant, $language, $fields, $kind);

        $model = $this->modelFor($merchant, $kind) ?? throw new ReviewRejected('No AI model is available right now.');
        $price = $this->prices->resolve($this->action($kind), $model);

        $reservation = $options['reservation']
            ?? $this->wallets->reserve($merchant->merchant_id, $price['credits'], $this->action($kind), $price['source']);

        $generation = ContentGeneration::query()->create([
            'merchant_id' => $merchant->merchant_id,
            'product_id' => $product->id,
            'lang' => $language,
            'kind' => $kind,
            'fields' => array_values($fields),
            'status' => ContentGeneration::QUEUED,
            'ai_model_id' => $model->id,
            'keywords' => $options['keywords'] ?? null,
            'instruction' => $options['instruction'] ?? null,
            'credits' => $reservation->amount,
            'reservation_id' => $reservation->id,
            'bulk_job_id' => $options['bulk_job_id'] ?? null,
            'salla_user_id' => $sallaUserId,
            'source_hash' => $product->relevant_hash,
        ]);

        $reservation->forceFill(['reference_type' => ContentGeneration::class, 'reference_id' => (string) $generation->id])->save();

        if ($options['dispatch'] ?? true) {
            RunContentGeneration::dispatch($merchant->merchant_id, $generation->id)->onQueue(config('salla.queue'));
        }

        return $generation;
    }

    /**
     * Run a queued generation. Never throws: every failure ends in a visible status and released credits.
     */
    public function process(ContentGeneration $generation): ContentGeneration
    {
        if ($generation->status !== ContentGeneration::QUEUED) {
            return $generation;
        }

        $generation->forceFill(['status' => ContentGeneration::RUNNING])->save();

        $merchant = Merchant::findBySallaId($generation->merchant_id);
        $product = Product::query()->find($generation->product_id);

        if (! $merchant || ! $product) {
            return $this->fail($generation, ContentGeneration::FAILED, 'The product is no longer available.');
        }

        try {
            return $this->generate($merchant, $product, $generation);
        } catch (ModerationBlocked $blocked) {
            return $this->fail($generation, ContentGeneration::BLOCKED, $blocked->getMessage());
        } catch (Throwable $exception) {
            report($exception);

            return $this->fail($generation, ContentGeneration::FAILED, $exception instanceof AiUnavailable || $exception instanceof ModerationUnavailable
                ? $exception->getMessage()
                : 'The AI service could not complete this request.');
        }
    }

    private function generate(Merchant $merchant, Product $product, ContentGeneration $generation): ContentGeneration
    {
        $language = $generation->lang;
        $fields = $generation->fields;
        $context = new AiContext($merchant->merchant_id, 'product_content', $generation->kind === 'field' ? 'field_regeneration' : 'product_content', $generation->salla_user_id, true, 0, ContentGeneration::class, (string) $generation->id);
        $model = AiModel::query()->findOrFail($generation->ai_model_id);

        $productContext = $this->contexts->ensure($merchant, $product->load('translations'), $generation->salla_user_id);

        foreach ([$generation->keywords => 'keywords', $generation->instruction => 'instruction'] as $text => $type) {
            $this->moderation->checkText($context, $text, "content_{$type}", (string) $generation->id);
        }

        $system = $this->composer->system($merchant, $product, $productContext, $language, $fields, $generation->keywords);
        $request = new TextRequest($model->provider_model_id, $system, [['role' => 'user', 'content' => $this->composer->user($product, $language, $fields, $generation->instruction)]], maxTokens: 4096);

        $data = $this->gateway->structured($context->charging($generation->credits), $model, $request, $this->composer->schema($fields));
        $values = $this->normalize($data, $language, $fields);
        $values = $this->shorten($merchant, $model, $context, $system, $product, $generation, $values);

        $manual = FieldLimits::overLimit($values);
        $this->moderation->checkText($context, $this->textOf($values), 'content_output', (string) $generation->id);

        $output = $values;
        unset($output['alt']);

        if (isset($values['alt'])) {
            $this->saveAlts($merchant, $product, $generation, $language, $values['alt']);
            $output['alt'] = $values['alt'];
        }

        $generation->forceFill([
            'status' => ContentGeneration::DRAFT,
            'output' => $output,
            'manual_fields' => $manual === [] ? null : $manual,
            'expires_at' => Date::now()->addDays((int) config('revo.limits.draft_days')),
            'error' => null,
        ])->save();

        $this->capture($generation);

        if ($this->settings->settingsFor($merchant->merchant_id)->auto_publish) {
            $this->review->approve($generation->fresh(), merchant: $merchant, sallaUserId: $generation->salla_user_id, acknowledgeOutdated: true, silent: true);
        }

        return $generation->fresh();
    }

    /**
     * One automatic retry for overlong fields; whatever is still too long is left for manual editing at no extra cost.
     *
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private function shorten(Merchant $merchant, AiModel $model, AiContext $context, string $system, Product $product, ContentGeneration $generation, array $values): array
    {
        $over = FieldLimits::overLimit($values);

        if ($over === []) {
            return $values;
        }

        $limits = collect($over)->map(fn (string $field) => "{$field} must be at most ".FieldLimits::limit($field).' characters')->implode('; ');
        $request = new TextRequest($model->provider_model_id, $system, [['role' => 'user', 'content' => $this->composer->user($product, $generation->lang, $over, "Your previous answer was too long. Rewrite shorter: {$limits}.")]], maxTokens: 2048);

        try {
            $data = $this->gateway->structured($context->for('product_content', 'content_shorten'), $model, $request, $this->composer->schema($over));
        } catch (Throwable) {
            return $values;
        }

        return [...$values, ...$this->normalize($data, $generation->lang, $over)];
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<int, string>  $fields
     * @return array<string, mixed>
     */
    private function normalize(array $data, string $language, array $fields): array
    {
        $values = [];

        foreach ($fields as $field) {
            $value = $data[$field] ?? null;

            if ($field === 'alt') {
                $values['alt'] = collect(is_array($value) ? $value : [])
                    ->filter(fn ($item) => is_array($item) && isset($item['image_id'], $item['alt']))
                    ->map(fn (array $item) => ['image_id' => (int) $item['image_id'], 'alt' => trim(strip_tags((string) $item['alt']))])
                    ->values()->all();

                continue;
            }

            $value = is_string($value) ? trim($value) : '';

            $values[$field] = match ($field) {
                'description' => $this->sanitizer->sanitize($value, $language),
                'metadata_url' => $this->slugs->make($value, $language),
                default => trim(strip_tags($value)),
            };
        }

        return $values;
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function textOf(array $values): string
    {
        return collect($values)->map(fn ($value, $field) => $field === 'alt' ? collect($value)->pluck('alt')->implode(' ') : strip_tags((string) $value))->filter()->implode("\n");
    }

    /**
     * Alt text is stored per image and language and used on the next upload of that image (FR-PRD-015).
     *
     * @param  array<int, array{image_id: int, alt: string}>  $alts
     */
    private function saveAlts(Merchant $merchant, Product $product, ContentGeneration $generation, string $language, array $alts): void
    {
        foreach ($alts as $item) {
            $image = ProductImage::query()->where('product_id', $product->id)->where('salla_image_id', $item['image_id'])->first();

            if ($image && $item['alt'] !== '') {
                ProductImageAlt::query()->updateOrCreate(
                    ['product_image_id' => $image->id, 'lang' => $language],
                    ['merchant_id' => $merchant->merchant_id, 'alt' => mb_substr($item['alt'], 0, (int) FieldLimits::limit('alt')), 'generation_id' => $generation->id],
                );
            }
        }
    }

    private function capture(ContentGeneration $generation): void
    {
        if ($generation->reservation_id) {
            $reservation = CreditReservation::query()->find($generation->reservation_id);
            $reservation && $this->wallets->capture($reservation);
        }
    }

    private function fail(ContentGeneration $generation, string $status, string $message): ContentGeneration
    {
        if ($generation->reservation_id) {
            $reservation = CreditReservation::query()->find($generation->reservation_id);
            $reservation && $this->wallets->release($reservation, $message);
        }

        $generation->forceFill(['status' => $status, 'error' => $message, 'credits' => 0])->save();

        return $generation;
    }

    /**
     * @param  array<int, string>  $fields
     *
     * @throws ReviewRejected
     */
    private function validateRequest(Merchant $merchant, string $language, array $fields, string $kind): void
    {
        $errors = [];

        if (! in_array($language, $merchant->enabled_languages ?? config('revo.product.languages'), true)) {
            $errors['lang'] = ['That language is not enabled for this store.'];
        }

        if ($fields === [] || array_diff($fields, FieldLimits::ALL_FIELDS) !== []) {
            $errors['fields'] = ['Choose at least one valid field.'];
        } elseif ($kind === 'field' && count($fields) !== 1) {
            $errors['fields'] = ['Regenerate one field at a time.'];
        }

        if ($errors !== []) {
            throw new ReviewRejected('Invalid request.', $errors);
        }
    }

    private function modelFor(Merchant $merchant, string $kind): ?AiModel
    {
        $feature = $kind === 'field' ? 'field_regeneration' : 'product_content';
        $selected = $this->settings->settingsFor($merchant->merchant_id)->models['product_content'] ?? null;

        return $this->models->resolve($feature, $selected);
    }

    private function action(string $kind): string
    {
        return $kind === 'field' ? 'field_regeneration' : 'product_content';
    }
}
