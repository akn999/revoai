<?php

namespace App\Settings;

use App\Ai\AiContext;
use App\Ai\ModelResolver;
use App\Models\ContextOption;
use App\Models\FormDraft;
use App\Models\Merchant;
use App\Models\Preset;
use App\Models\PromptDefault;
use App\Models\SettingsAudit;
use App\Models\StoreContext;
use App\Models\StoreContextEntry;
use App\Models\StoreSetting;
use App\Moderation\ModerationBlocked;
use App\Moderation\ModerationService;
use App\Platform\RevoSettings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * Everything a store can configure. Prompts, custom presets and store-context text are moderated
 * on save; every change is audited with the Salla user id (FR-SET-006, FR-SET-007).
 */
class SettingsService
{
    public const FEATURE_MODELS = ['product_content' => 'product_content', 'image_edit' => 'image_edit'];

    public function __construct(
        private ModerationService $moderation,
        private ModelResolver $models,
        private PromptService $prompts,
        private PresetParameterValidator $parameters,
    ) {}

    public function settingsFor(int $merchantId): StoreSetting
    {
        return StoreSetting::query()->firstOrCreate(['merchant_id' => $merchantId], [
            'languages' => null, 'auto_publish' => false, 'description_length' => 'medium', 'description_structure' => 'paragraphs', 'default_variants' => 1,
        ]);
    }

    /**
     * Languages the store generates in: its choice limited to the store's enabled languages (FR-SET-002).
     *
     * @return array<int, string>
     */
    public function languages(Merchant $merchant): array
    {
        $enabled = $merchant->enabled_languages ?? config('revo.product.languages');
        $chosen = $this->settingsFor($merchant->merchant_id)->languages;

        $languages = array_values(array_intersect($chosen ?: [$merchant->default_language], $enabled));

        return $languages !== [] ? $languages : [$merchant->default_language];
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function updateGeneral(Merchant $merchant, array $input, ?int $sallaUserId): StoreSetting
    {
        $validated = Validator::make($input, [
            'languages' => ['sometimes', 'array', 'min:1'],
            'languages.*' => ['string', 'in:'.implode(',', $merchant->enabled_languages ?? config('revo.product.languages'))],
            'auto_publish' => ['sometimes', 'boolean'],
            'description_length' => ['sometimes', 'in:short,medium,long'],
            'description_structure' => ['sometimes', 'in:paragraphs,bullets'],
            'default_variants' => ['sometimes', 'integer', 'min:1', 'max:'.config('revo.images.max_variants')],
            'models.product_content' => ['sometimes', 'nullable', 'integer'],
            'models.image_edit' => ['sometimes', 'nullable', 'integer'],
            'image_defaults' => ['sometimes', 'nullable', 'array'],
        ])->validate();

        $settings = $this->settingsFor($merchant->merchant_id);
        $before = $settings->only(array_keys($validated));

        if (isset($validated['models'])) {
            foreach ($validated['models'] as $feature => $modelId) {
                if ($modelId !== null && $this->models->options($feature)->doesntContain('id', $modelId)) {
                    throw new SettingsException('That model is not available.', ['models.'.$feature => ['That model is not available.']]);
                }
            }

            $validated['models'] = array_filter([...($settings->models ?? []), ...$validated['models']], fn ($id) => $id !== null);
        }

        if (isset($validated['image_defaults'])) {
            $model = $this->models->resolve('image_edit', $validated['models']['image_edit'] ?? $settings->models['image_edit'] ?? null);
            $this->parameters->validate($model?->param_schema, $validated['image_defaults'], 'image_defaults');
        }

        $settings->fill($validated)->save();
        $this->audit($merchant->merchant_id, $sallaUserId, 'general', $before, $settings->only(array_keys($validated)));

        return $settings;
    }

    public function savePrompt(Merchant $merchant, string $key, ?string $body, ?int $sallaUserId): void
    {
        if (! in_array($key, PromptDefault::keys(), true)) {
            throw new SettingsException('Unknown prompt.', ['key' => ['Unknown prompt.']]);
        }

        $before = ['override' => $this->prompts->override($merchant->merchant_id, $key)];

        if ($body === null || trim($body) === '') {
            $this->prompts->reset($merchant->merchant_id, $key);
            $this->audit($merchant->merchant_id, $sallaUserId, "prompt.{$key}", $before, ['override' => null]);

            return;
        }

        $this->moderateOrFail($merchant, $body, 'prompt_override', $key, $sallaUserId);
        $this->prompts->saveOverride($merchant->merchant_id, $key, $body);
        $this->audit($merchant->merchant_id, $sallaUserId, "prompt.{$key}", $before, ['override' => $body]);
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function saveStoreContext(Merchant $merchant, array $input, ?int $sallaUserId): StoreContext
    {
        $max = (int) config('revo.limits.store_context_field_chars');
        $options = fn (string $field): array => ContextOption::query()->where('field', $field)->active()->pluck('key')->all();

        $validated = Validator::make($input, [
            'store_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'tagline' => ['sometimes', 'nullable', 'string', 'max:255'],
            'slogan' => ['sometimes', 'nullable', 'string', 'max:255'],
            'industry' => ['sometimes', 'nullable', 'in:'.implode(',', $options('industry'))],
            'audience' => ['sometimes', 'nullable', 'array'],
            'audience.*' => ['in:'.implode(',', $options('audience'))],
            'brand_tone' => ['sometimes', 'nullable', 'in:'.implode(',', $options('brand_tone'))],
            'photography_style' => ['sometimes', 'nullable', 'in:'.implode(',', $options('photography_style'))],
            'brand_colors' => ['sometimes', 'nullable', 'array', 'min:2', 'max:5'],
            'brand_colors.*' => ['string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'delivery_policy' => ['sometimes', 'nullable', 'string', "max:{$max}"],
            'return_policy' => ['sometimes', 'nullable', 'string', "max:{$max}"],
            'returns_accepted' => ['sometimes', 'boolean'],
            'privacy_policy' => ['sometimes', 'nullable', 'string', "max:{$max}"],
            'faq' => ['sometimes', 'nullable', 'array'],
            'faq.*.question' => ['required', 'string', "max:{$max}"],
            'faq.*.answer' => ['required', 'string', "max:{$max}"],
            'entries' => ['sometimes', 'array'],
            'entries.*.label' => ['required', 'string', 'max:255'],
            'entries.*.text' => ['required', 'string', "max:{$max}"],
        ])->validate();

        $texts = collect(['tagline', 'slogan', 'delivery_policy', 'return_policy', 'privacy_policy'])
            ->map(fn (string $field) => $validated[$field] ?? null)
            ->merge(collect($validated['faq'] ?? [])->flatMap(fn (array $pair): array => [$pair['question'], $pair['answer']]))
            ->merge(collect($validated['entries'] ?? [])->flatMap(fn (array $entry): array => [$entry['label'], $entry['text']]))
            ->filter()->implode("\n");

        $this->moderateOrFail($merchant, $texts, 'store_context', null, $sallaUserId);

        return DB::transaction(function () use ($merchant, $validated, $sallaUserId): StoreContext {
            $context = StoreContext::query()->firstOrCreate(['merchant_id' => $merchant->merchant_id]);
            $before = $context->only(array_keys(array_diff_key($validated, ['entries' => 1])));

            $context->fill(array_diff_key($validated, ['entries' => 1]))->save();

            if (array_key_exists('entries', $validated)) {
                StoreContextEntry::query()->where('merchant_id', $merchant->merchant_id)->delete();

                foreach (array_values($validated['entries']) as $index => $entry) {
                    StoreContextEntry::query()->create(['merchant_id' => $merchant->merchant_id, 'label' => $entry['label'], 'text' => $entry['text'], 'sort' => $index]);
                }
            }

            $this->audit($merchant->merchant_id, $sallaUserId, 'store_context', $before, $context->only(array_keys($before)));

            return $context;
        });
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function savePreset(Merchant $merchant, array $input, ?int $sallaUserId, ?Preset $preset = null): Preset
    {
        $validated = Validator::make($input, [
            'name_ar' => ['required', 'string', 'max:255'],
            'name_en' => ['required', 'string', 'max:255'],
            'prompt' => ['required', 'string', 'max:'.config('revo.limits.store_context_field_chars')],
            'ai_model_id' => ['nullable', 'integer'],
            'params' => ['nullable', 'array'],
        ])->validate();

        if ($preset && $preset->merchant_id !== $merchant->merchant_id) {
            throw new SettingsException('Preset not found.');
        }

        if ($validated['ai_model_id'] ?? null) {
            if ($this->models->options('image_edit')->doesntContain('id', $validated['ai_model_id'])) {
                throw new SettingsException('That model is not available.', ['ai_model_id' => ['That model is not available.']]);
            }
        }

        $model = $this->models->resolve('image_edit', $validated['ai_model_id'] ?? null);
        $this->parameters->validate($model?->param_schema, $validated['params'] ?? [], 'params');
        $this->moderateOrFail($merchant, $validated['prompt'], 'preset_prompt', null, $sallaUserId);

        $preset ??= new Preset(['merchant_id' => $merchant->merchant_id, 'active' => true]);
        $before = $preset->exists ? $preset->only(['name_ar', 'name_en', 'prompt', 'ai_model_id', 'params']) : null;
        $preset->fill($validated)->save();
        $this->audit($merchant->merchant_id, $sallaUserId, 'preset', $before, $preset->only(['name_ar', 'name_en', 'prompt', 'ai_model_id', 'params']));

        return $preset;
    }

    public function deletePreset(Merchant $merchant, Preset $preset, ?int $sallaUserId): void
    {
        if ($preset->merchant_id !== $merchant->merchant_id) {
            throw new SettingsException('Preset not found.');
        }

        $this->audit($merchant->merchant_id, $sallaUserId, 'preset', $preset->only(['name_en', 'prompt']), null);
        $preset->delete();
    }

    /**
     * Autosaved drafts so a reload loses nothing (FR-PLT-010).
     *
     * @param  array<string, mixed>  $payload
     */
    public function saveDraft(int $merchantId, ?int $sallaUserId, string $formKey, array $payload): FormDraft
    {
        return FormDraft::query()->updateOrCreate(
            ['merchant_id' => $merchantId, 'salla_user_id' => $sallaUserId ?? 0, 'form_key' => $formKey],
            ['payload' => $payload],
        );
    }

    public function draft(int $merchantId, ?int $sallaUserId, string $formKey): ?FormDraft
    {
        return FormDraft::query()->where('merchant_id', $merchantId)->where('salla_user_id', $sallaUserId ?? 0)->where('form_key', $formKey)->first();
    }

    public function discardDraft(int $merchantId, ?int $sallaUserId, string $formKey): void
    {
        FormDraft::query()->where('merchant_id', $merchantId)->where('salla_user_id', $sallaUserId ?? 0)->where('form_key', $formKey)->delete();
    }

    /**
     * Everything the Settings screen shows.
     *
     * @return array<string, mixed>
     */
    public function snapshot(Merchant $merchant): array
    {
        $settings = $this->settingsFor($merchant->merchant_id);
        $context = StoreContext::query()->where('merchant_id', $merchant->merchant_id)->first();
        $selected = $settings->models ?? [];

        return [
            'general' => [
                'languages' => $this->languages($merchant),
                'enabled_languages' => $merchant->enabled_languages ?? config('revo.product.languages'),
                'auto_publish' => $settings->auto_publish,
                'description_length' => $settings->description_length,
                'description_structure' => $settings->description_structure,
                'default_variants' => $settings->default_variants,
                'image_defaults' => $settings->image_defaults ?? [],
            ],
            'prompts' => $this->prompts->all($merchant->merchant_id),
            'models' => collect(self::FEATURE_MODELS)->mapWithKeys(fn (string $feature): array => [$feature => [
                'selected' => $selected[$feature] ?? null,
                'effective' => $this->models->resolve($feature, $selected[$feature] ?? null)?->id,
                'fell_back' => $this->models->selectionFellBack($feature, $selected[$feature] ?? null),
                'options' => $this->models->options($feature)->map(fn ($model) => [
                    'id' => $model->id, 'name_ar' => $model->name_ar, 'name_en' => $model->name_en,
                    'price' => $model->priceFor($feature) ?? app(RevoSettings::class)->price($feature),
                    'param_schema' => $model->param_schema,
                ])->all(),
            ]])->all(),
            'presets' => Preset::query()->availableTo($merchant->merchant_id)->where('active', true)->orderBy('sort')->orderBy('id')->get()
                ->map(fn (Preset $preset) => [
                    'id' => $preset->id, 'name_ar' => $preset->name_ar, 'name_en' => $preset->name_en, 'prompt' => $preset->prompt,
                    'ai_model_id' => $preset->ai_model_id, 'params' => $preset->params, 'default' => $preset->isDefault(),
                ])->all(),
            'store_context' => $context?->toArray() ?? [],
            'entries' => StoreContextEntry::query()->where('merchant_id', $merchant->merchant_id)->orderBy('sort')->get(['label', 'text'])->all(),
            'options' => ContextOption::query()->active()->orderBy('field')->orderBy('sort')->get()
                ->groupBy('field')->map(fn ($group) => $group->map(fn (ContextOption $option) => [
                    'key' => $option->key, 'label_ar' => $option->label_ar, 'label_en' => $option->label_en,
                ])->values())->all(),
        ];
    }

    private function moderateOrFail(Merchant $merchant, string $text, string $subjectType, ?string $subjectId, ?int $sallaUserId): void
    {
        try {
            $this->moderation->checkText(new AiContext($merchant->merchant_id, 'settings', 'text_moderation', $sallaUserId), $text, $subjectType, $subjectId);
        } catch (ModerationBlocked $blocked) {
            throw new SettingsException($blocked->getMessage(), ['moderation' => [$blocked->getMessage()]]);
        }
    }

    /**
     * @param  array<string, mixed>|null  $old
     * @param  array<string, mixed>|null  $new
     */
    private function audit(int $merchantId, ?int $sallaUserId, string $group, ?array $old, ?array $new): void
    {
        SettingsAudit::query()->create(['merchant_id' => $merchantId, 'salla_user_id' => $sallaUserId, 'group' => $group, 'old' => $old, 'new' => $new]);
    }
}
