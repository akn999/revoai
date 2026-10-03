<?php

use App\Models\AiModel;
use App\Models\ContextOption;
use App\Models\FormDraft;
use App\Models\Merchant;
use App\Models\ModerationEvent;
use App\Models\Preset;
use App\Models\PromptDefault;
use App\Models\SettingsAudit;
use App\Models\StoreContext;
use App\Models\StoreContextEntry;
use App\Models\StorePrompt;
use App\Settings\PromptService;
use App\Settings\SettingsException;
use App\Settings\SettingsService;
use App\Settings\StoreContextCompiler;
use Database\Seeders\ContextOptionSeeder;
use Database\Seeders\ModerationCategorySeeder;
use Database\Seeders\PromptDefaultSeeder;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->seed([PromptDefaultSeeder::class, ContextOptionSeeder::class, ModerationCategorySeeder::class]);
    $this->moderationText = AiModel::factory()->defaultFor('text_moderation')->create(['features' => ['text_moderation'], 'provider_model_id' => 'mod.text']);
    $this->contentModel = AiModel::factory()->defaultFor('product_content')->create(['features' => ['product_content'], 'name_en' => 'Default content']);
    $this->imageModel = AiModel::factory()->image()->defaultFor('image_edit')->create(['provider_model_id' => 'fal/edit', 'param_schema' => [
        'type' => 'object',
        'properties' => [
            'seed' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 1000],
            'guidance_scale' => ['type' => 'number', 'minimum' => 1, 'maximum' => 20],
            'output_format' => ['type' => 'string', 'enum' => ['jpeg', 'png']],
            'negative_prompt' => ['type' => 'string', 'maxLength' => 20],
        ],
    ]]);
    $this->fakeText->using(fn () => ['blocked' => false, 'category' => null]);
    $this->merchant = Merchant::factory()->active()->create(['enabled_languages' => ['ar', 'en'], 'default_language' => 'ar']);
    $this->other = Merchant::factory()->active()->create(['enabled_languages' => ['ar', 'en']]);
    $this->settings = app(SettingsService::class);
});

describe('general settings', function () {
    test('defaults: the default language only, auto-publish off, medium paragraphs', function () {
        $settings = $this->settings->settingsFor($this->merchant->merchant_id);

        expect($settings)->auto_publish->toBeFalse()->description_length->toBe('medium')->description_structure->toBe('paragraphs')->default_variants->toBe(1)
            ->and($this->settings->languages($this->merchant))->toBe(['ar']);
    });

    test('the merchant can change every general setting and each change is audited with the Salla user', function () {
        $this->settings->updateGeneral($this->merchant, [
            'languages' => ['ar', 'en'], 'auto_publish' => true, 'description_length' => 'long', 'description_structure' => 'bullets', 'default_variants' => 3,
        ], 4242);

        $settings = $this->settings->settingsFor($this->merchant->merchant_id);
        $audit = SettingsAudit::sole();
        expect($settings)->auto_publish->toBeTrue()->description_length->toBe('long')->description_structure->toBe('bullets')->default_variants->toBe(3)
            ->and($this->settings->languages($this->merchant))->toBe(['ar', 'en'])
            ->and($audit)->merchant_id->toBe($this->merchant->merchant_id)->salla_user_id->toBe(4242)->group->toBe('general')
            ->and($audit->old['description_length'])->toBe('medium')->and($audit->new['description_length'])->toBe('long');
    });

    test('a language that the store has not enabled cannot be selected', function () {
        $this->merchant->update(['enabled_languages' => ['ar']]);

        expect(fn () => $this->settings->updateGeneral($this->merchant, ['languages' => ['ar', 'en']], 1))->toThrow(ValidationException::class);
    });

    test('a stored language that was disabled later is dropped from the effective selection', function () {
        $this->settings->updateGeneral($this->merchant, ['languages' => ['ar', 'en']], 1);
        $this->merchant->update(['enabled_languages' => ['ar']]);

        expect($this->settings->languages($this->merchant->fresh()))->toBe(['ar']);
    });

    test('validation rejects out-of-range values', function (array $input) {
        expect(fn () => $this->settings->updateGeneral($this->merchant, $input, 1))->toThrow(ValidationException::class);
    })->with([
        'length' => [['description_length' => 'huge']],
        'structure' => [['description_structure' => 'poem']],
        'variants too many' => [['default_variants' => 4]],
        'variants zero' => [['default_variants' => 0]],
        'no languages' => [['languages' => []]],
    ]);

    test('two different Salla users of one store can both save settings (no roles in phase 1)', function () {
        $this->settings->updateGeneral($this->merchant, ['auto_publish' => true], 1);
        $this->settings->updateGeneral($this->merchant, ['auto_publish' => false], 2);

        expect(SettingsAudit::orderBy('id')->pluck('salla_user_id')->all())->toBe([1, 2]);
    });

    test('settings of one store never reach another', function () {
        $this->settings->updateGeneral($this->merchant, ['auto_publish' => true], 1);

        expect($this->settings->settingsFor($this->other->merchant_id)->auto_publish)->toBeFalse();
    });
});

describe('model selection', function () {
    test('only active catalog models of the feature can be selected', function () {
        $choice = AiModel::factory()->create(['features' => ['product_content']]);
        $inactive = AiModel::factory()->inactive()->create(['features' => ['product_content']]);

        $this->settings->updateGeneral($this->merchant, ['models' => ['product_content' => $choice->id]], 1);
        expect($this->settings->settingsFor($this->merchant->merchant_id)->models['product_content'])->toBe($choice->id);

        expect(fn () => $this->settings->updateGeneral($this->merchant, ['models' => ['product_content' => $inactive->id]], 1))->toThrow(SettingsException::class)
            ->and(fn () => $this->settings->updateGeneral($this->merchant, ['models' => ['product_content' => $this->imageModel->id]], 1))->toThrow(SettingsException::class);
    });

    test('a model deactivated later falls back to the default and the snapshot says so', function () {
        $choice = AiModel::factory()->create(['features' => ['product_content']]);
        $this->settings->updateGeneral($this->merchant, ['models' => ['product_content' => $choice->id]], 1);
        $choice->update(['active' => false]);

        $models = $this->settings->snapshot($this->merchant)['models']['product_content'];

        expect($models['selected'])->toBe($choice->id)->and($models['effective'])->toBe($this->contentModel->id)->and($models['fell_back'])->toBeTrue()
            ->and(collect($models['options'])->pluck('id')->all())->not->toContain($choice->id);
    });

    test('options show each model with its price', function () {
        AiModel::factory()->create(['features' => ['product_content'], 'prices' => ['product_content' => 12]]);

        $options = collect($this->settings->snapshot($this->merchant)['models']['product_content']['options']);

        expect($options->pluck('price')->sort()->values()->all())->toBe([8, 12]);
    });

    test('image defaults are validated against the selected model schema', function () {
        $ok = $this->settings->updateGeneral($this->merchant, ['image_defaults' => ['seed' => 5, 'output_format' => 'png']], 1);
        expect($ok->image_defaults)->toBe(['seed' => 5, 'output_format' => 'png']);

        foreach ([['seed' => 2000], ['seed' => -1], ['guidance_scale' => 25], ['output_format' => 'gif'], ['negative_prompt' => str_repeat('x', 21)], ['unknown' => 1]] as $bad) {
            expect(fn () => $this->settings->updateGeneral($this->merchant, ['image_defaults' => $bad], 1))->toThrow(SettingsException::class);
        }
    });
});

describe('prompts', function () {
    beforeEach(fn () => $this->prompts = app(PromptService::class));

    test('a store follows the global default until it overrides', function () {
        $default = PromptDefault::where('key', 'product_tone')->first();

        expect($this->prompts->effective($this->merchant->merchant_id, 'product_tone'))->toBe($default->body)
            ->and($this->prompts->override($this->merchant->merchant_id, 'product_tone'))->toBeNull();
    });

    test('an override applies to this store only', function () {
        $this->settings->savePrompt($this->merchant, 'product_tone', 'Write like a poet.', 7);

        expect($this->prompts->effective($this->merchant->merchant_id, 'product_tone'))->toBe('Write like a poet.')
            ->and($this->prompts->effective($this->other->merchant_id, 'product_tone'))->toBe(PromptDefault::where('key', 'product_tone')->value('body'))
            ->and(SettingsAudit::sole())->group->toBe('prompt.product_tone')->salla_user_id->toBe(7);
    });

    test('reset deletes the override so later default changes reach the store again', function () {
        $this->settings->savePrompt($this->merchant, 'product_tone', 'Custom', 1);
        $this->settings->savePrompt($this->merchant, 'product_tone', null, 1);

        PromptDefault::where('key', 'product_tone')->update(['body' => 'New global default']);

        expect(StorePrompt::count())->toBe(0)->and($this->prompts->effective($this->merchant->merchant_id, 'product_tone'))->toBe('New global default');
    });

    test('blank text also resets', function () {
        $this->settings->savePrompt($this->merchant, 'product_tone', 'Custom', 1);
        $this->settings->savePrompt($this->merchant, 'product_tone', '   ', 1);

        expect(StorePrompt::count())->toBe(0);
    });

    test('changing the global default changes the next effective prompt of stores without an override', function () {
        PromptDefault::where('key', 'image_editing')->update(['body' => 'Brand new default']);

        expect($this->prompts->effective($this->merchant->merchant_id, 'image_editing'))->toBe('Brand new default');
    });

    test('every prompt is listed with its default, override and effective text', function () {
        $this->settings->savePrompt($this->merchant, 'chat_system', 'Be brief.', 1);

        $all = $this->prompts->all($this->merchant->merchant_id);

        expect(array_keys($all))->toBe(PromptDefault::keys())
            ->and($all['chat_system'])->override->toBe('Be brief.')->effective->toBe('Be brief.')
            ->and($all['product_tone']['override'])->toBeNull();
    });

    test('an unknown prompt key is refused', function () {
        expect(fn () => $this->settings->savePrompt($this->merchant, 'nope', 'x', 1))->toThrow(SettingsException::class);
    });

    test('a blocked prompt fails with the category and loses nothing else', function () {
        $this->fakeText->using(fn () => ['blocked' => true, 'category' => 'violence']);

        try {
            $this->settings->savePrompt($this->merchant, 'product_tone', 'show gore', 1);
            $this->fail('expected a block');
        } catch (SettingsException $exception) {
            expect($exception->getMessage())->toBe('Blocked: Violence and gore');
        }

        expect(StorePrompt::count())->toBe(0)->and(ModerationEvent::sole())->subject_type->toBe('prompt_override')->decision->toBe('blocked')->and(SettingsAudit::count())->toBe(0);
    });
});

describe('store context', function () {
    test('the merchant saves context fields with dropdowns limited to active options', function () {
        $context = $this->settings->saveStoreContext($this->merchant, [
            'store_name' => 'Alpha', 'tagline' => 'Best store', 'slogan' => 'Shop smart', 'industry' => 'fashion', 'audience' => ['women', 'teens'],
            'brand_tone' => 'luxury', 'photography_style' => 'studio', 'brand_colors' => ['#112233', '#445566'],
            'delivery_policy' => 'Ships in 3 days', 'return_policy' => '14 days', 'returns_accepted' => true, 'privacy_policy' => 'We respect privacy',
            'faq' => [['question' => 'Do you ship?', 'answer' => 'Yes']],
        ], 5);

        expect($context)->store_name->toBe('Alpha')->industry->toBe('fashion')->audience->toBe(['women', 'teens'])->returns_accepted->toBeTrue()
            ->brand_colors->toBe(['#112233', '#445566'])->faq->toBe([['question' => 'Do you ship?', 'answer' => 'Yes']])
            ->and(StoreContext::count())->toBe(1);
    });

    test('invalid input is refused', function (array $input) {
        expect(fn () => $this->settings->saveStoreContext($this->merchant, $input, 1))->toThrow(ValidationException::class);
    })->with([
        'unknown industry' => [['industry' => 'spaceships']],
        'unknown audience' => [['audience' => ['aliens']]],
        'one color' => [['brand_colors' => ['#112233']]],
        'six colors' => [['brand_colors' => ['#111111', '#222222', '#333333', '#444444', '#555555', '#666666']]],
        'bad color' => [['brand_colors' => ['#112233', 'red']]],
        'policy too long' => [['delivery_policy' => str_repeat('a', 5001)]],
        'faq without answer' => [['faq' => [['question' => 'q']]]],
    ]);

    test('free text up to 5,000 characters is accepted and 5,001 is not', function () {
        $this->settings->saveStoreContext($this->merchant, ['privacy_policy' => str_repeat('a', 5000)], 1);

        expect(fn () => $this->settings->saveStoreContext($this->merchant, ['privacy_policy' => str_repeat('a', 5001)], 1))->toThrow(ValidationException::class);
    });

    test('a switched-off option can no longer be chosen', function () {
        ContextOption::where(['field' => 'industry', 'key' => 'fashion'])->update(['active' => false]);

        expect(fn () => $this->settings->saveStoreContext($this->merchant, ['industry' => 'fashion'], 1))->toThrow(ValidationException::class);
    });

    test('additional entries replace the previous ones in order', function () {
        $this->settings->saveStoreContext($this->merchant, ['entries' => [['label' => 'A', 'text' => '1'], ['label' => 'B', 'text' => '2']]], 1);
        $this->settings->saveStoreContext($this->merchant, ['entries' => [['label' => 'C', 'text' => '3']]], 1);

        expect(StoreContextEntry::orderBy('sort')->pluck('label')->all())->toBe(['C']);
    });

    test('saving only some fields leaves the others and the entries alone', function () {
        $this->settings->saveStoreContext($this->merchant, ['tagline' => 'One', 'slogan' => 'Two', 'entries' => [['label' => 'A', 'text' => '1']]], 1);

        $this->settings->saveStoreContext($this->merchant, ['tagline' => 'Changed'], 1);

        expect(StoreContext::sole())->tagline->toBe('Changed')->slogan->toBe('Two')->and(StoreContextEntry::count())->toBe(1);
    });

    test('blocked text keeps the previous value, names the category and writes no audit', function () {
        $this->settings->saveStoreContext($this->merchant, ['tagline' => 'Original'], 1);
        $auditsBefore = SettingsAudit::count();
        $this->fakeText->using(fn () => ['blocked' => true, 'category' => 'gambling']);

        try {
            $this->settings->saveStoreContext($this->merchant, ['tagline' => 'Bet now!'], 1);
            $this->fail('expected a block');
        } catch (SettingsException $exception) {
            expect($exception->getMessage())->toContain('Gambling');
        }

        expect(StoreContext::sole()->tagline)->toBe('Original')->and(SettingsAudit::count())->toBe($auditsBefore);
    });

    test('every free-text field and entry is moderated together', function () {
        $this->settings->saveStoreContext($this->merchant, ['tagline' => 'T', 'delivery_policy' => 'D', 'entries' => [['label' => 'L', 'text' => 'X']]], 1);

        $sent = $this->fakeText->lastRequest()->messages[0]['content'];
        expect($sent)->toContain('T')->toContain('D')->toContain('L')->toContain('X');
    });

    test('a store only ever reads its own context', function () {
        $this->settings->saveStoreContext($this->merchant, ['tagline' => 'Mine'], 1);
        $this->settings->saveStoreContext($this->other, ['tagline' => 'Theirs'], 1);

        $compiler = app(StoreContextCompiler::class);
        expect($compiler->compile($this->merchant->merchant_id, 'products'))->toContain('Mine')->not->toContain('Theirs')
            ->and($compiler->compile($this->other->merchant_id, 'products'))->toContain('Theirs')->not->toContain('Mine');
    });
});

describe('store context compilation', function () {
    beforeEach(function () {
        $this->settings->saveStoreContext($this->merchant, [
            'store_name' => 'Alpha', 'tagline' => 'TAGLINE-MARK', 'slogan' => 'SLOGAN-MARK', 'industry' => 'fashion', 'audience' => ['women'],
            'brand_tone' => 'luxury', 'photography_style' => 'studio', 'brand_colors' => ['#112233', '#445566'],
            'delivery_policy' => 'DELIVERY-MARK', 'return_policy' => 'RETURN-MARK', 'returns_accepted' => true, 'privacy_policy' => 'PRIVACY-MARK',
            'faq' => [['question' => 'Q1', 'answer' => 'A1']], 'entries' => [['label' => 'Fabric', 'text' => 'FABRIC-MARK']],
        ], 1);
        $this->compiler = app(StoreContextCompiler::class);
    });

    test('an image edit prompt has the brand colors and photography style but not the privacy policy', function () {
        $images = $this->compiler->compile($this->merchant->merchant_id, 'images');

        expect($images)->toContain('#112233, #445566')->toContain('Photography style: Studio')->toContain('SLOGAN-MARK')->toContain('FABRIC-MARK')
            ->not->toContain('PRIVACY-MARK')->not->toContain('DELIVERY-MARK')->not->toContain('TAGLINE-MARK');
    });

    test('product content gets tone, tagline and slogan but not policies or colors', function () {
        $products = $this->compiler->compile($this->merchant->merchant_id, 'products');

        expect($products)->toContain('TAGLINE-MARK')->toContain('SLOGAN-MARK')->toContain('Brand tone: Luxury')->toContain('FABRIC-MARK')
            ->not->toContain('PRIVACY-MARK')->not->toContain('#112233')->not->toContain('Photography style');
    });

    test('chat context has the policies, FAQ and returns switch', function () {
        $chat = $this->compiler->compile($this->merchant->merchant_id, 'chat');

        expect($chat)->toContain('DELIVERY-MARK')->toContain('RETURN-MARK')->toContain('PRIVACY-MARK')->toContain('Q: Q1 A: A1')->toContain('Returns accepted: yes')
            ->not->toContain('#112233');
    });

    test('option keys are shown with the labels of the store language', function () {
        $english = $this->compiler->compile($this->merchant->merchant_id, 'products', 'en');
        $arabic = $this->compiler->compile($this->merchant->merchant_id, 'products', 'ar');

        expect($english)->toContain('Industry: Fashion')->and($arabic)->toContain('أزياء')->and($this->compiler->forMerchant($this->merchant, 'products'))->toContain('أزياء');
    });

    test('the compiled context is capped to the token budget', function () {
        $this->settings->saveStoreContext($this->merchant, ['entries' => collect(range(1, 5))->map(fn ($i) => ['label' => "E{$i}", 'text' => str_repeat('x', 5000)])->all()], 1);

        $text = $this->compiler->compile($this->merchant->merchant_id, 'products');

        expect(mb_strlen($text))->toBeLessThanOrEqual(1500 * 4)->and($text)->toContain('TAGLINE-MARK');
    });

    test('a store without context compiles to an empty string', function () {
        expect($this->compiler->compile($this->other->merchant_id, 'products'))->toBe('');
    });
});

describe('presets', function () {
    beforeEach(function () {
        $this->default = Preset::factory()->create(['name_en' => 'Default preset', 'sort' => 0]);
    });

    test('a custom preset is visible only in its own store, next to the defaults', function () {
        $this->settings->savePreset($this->merchant, ['name_ar' => 'قالبي', 'name_en' => 'Mine', 'prompt' => 'Make it pop'], 3);

        $mine = collect($this->settings->snapshot($this->merchant)['presets'])->pluck('name_en')->all();
        $theirs = collect($this->settings->snapshot($this->other)['presets'])->pluck('name_en')->all();

        expect($mine)->toContain('Default preset')->toContain('Mine')->and($theirs)->toContain('Default preset')->not->toContain('Mine');
    });

    test('a preset can carry a model and parameters that are validated against that model', function () {
        $preset = $this->settings->savePreset($this->merchant, ['name_ar' => 'ق', 'name_en' => 'P', 'prompt' => 'x', 'ai_model_id' => $this->imageModel->id, 'params' => ['seed' => 9]], 1);

        expect($preset)->ai_model_id->toBe($this->imageModel->id)->params->toBe(['seed' => 9])->merchant_id->toBe($this->merchant->merchant_id);

        expect(fn () => $this->settings->savePreset($this->merchant, ['name_ar' => 'ق', 'name_en' => 'P', 'prompt' => 'x', 'ai_model_id' => $this->imageModel->id, 'params' => ['seed' => 99999]], 1))
            ->toThrow(SettingsException::class);
    });

    test('a model that is not an active image-edit model is refused', function () {
        expect(fn () => $this->settings->savePreset($this->merchant, ['name_ar' => 'ق', 'name_en' => 'P', 'prompt' => 'x', 'ai_model_id' => $this->contentModel->id], 1))->toThrow(SettingsException::class);
    });

    test('the preset prompt is moderated on save', function () {
        $this->fakeText->using(fn () => ['blocked' => true, 'category' => 'nudity']);

        expect(fn () => $this->settings->savePreset($this->merchant, ['name_ar' => 'ق', 'name_en' => 'P', 'prompt' => 'bad'], 1))->toThrow(SettingsException::class, 'Nudity');
        expect(Preset::where('merchant_id', $this->merchant->merchant_id)->count())->toBe(0);
    });

    test('a store can edit and delete only its own presets, never defaults or another store\'s', function () {
        $mine = $this->settings->savePreset($this->merchant, ['name_ar' => 'ق', 'name_en' => 'Mine', 'prompt' => 'x'], 1);
        $theirs = $this->settings->savePreset($this->other, ['name_ar' => 'ق', 'name_en' => 'Theirs', 'prompt' => 'x'], 1);

        $updated = $this->settings->savePreset($this->merchant, ['name_ar' => 'ق', 'name_en' => 'Renamed', 'prompt' => 'y'], 1, $mine);
        expect($updated->name_en)->toBe('Renamed');

        expect(fn () => $this->settings->savePreset($this->merchant, ['name_ar' => 'ق', 'name_en' => 'Hack', 'prompt' => 'y'], 1, $theirs))->toThrow(SettingsException::class)
            ->and(fn () => $this->settings->savePreset($this->merchant, ['name_ar' => 'ق', 'name_en' => 'Hack', 'prompt' => 'y'], 1, $this->default))->toThrow(SettingsException::class)
            ->and(fn () => $this->settings->deletePreset($this->merchant, $theirs, 1))->toThrow(SettingsException::class)
            ->and(fn () => $this->settings->deletePreset($this->merchant, $this->default, 1))->toThrow(SettingsException::class);

        $this->settings->deletePreset($this->merchant, $mine, 1);
        expect(Preset::whereKey($mine->id)->exists())->toBeFalse()->and(Preset::whereKey($theirs->id)->exists())->toBeTrue();
    });

    test('inactive default presets are hidden from the editor', function () {
        $this->default->update(['active' => false]);

        expect(collect($this->settings->snapshot($this->merchant)['presets'])->pluck('name_en')->all())->not->toContain('Default preset');
    });

    test('preset changes are audited', function () {
        $this->settings->savePreset($this->merchant, ['name_ar' => 'ق', 'name_en' => 'Mine', 'prompt' => 'x'], 11);

        expect(SettingsAudit::where('group', 'preset')->sole())->salla_user_id->toBe(11);
    });
});

describe('drafts', function () {
    test('typing and reloading restores the draft, per user and per form', function () {
        $this->settings->saveDraft($this->merchant->merchant_id, 5, 'settings.prompts', ['product_tone' => 'half-written']);
        $this->settings->saveDraft($this->merchant->merchant_id, 6, 'settings.prompts', ['product_tone' => 'someone else']);

        expect($this->settings->draft($this->merchant->merchant_id, 5, 'settings.prompts')->payload)->toBe(['product_tone' => 'half-written'])
            ->and($this->settings->draft($this->merchant->merchant_id, 6, 'settings.prompts')->payload)->toBe(['product_tone' => 'someone else'])
            ->and($this->settings->draft($this->merchant->merchant_id, 5, 'settings.context'))->toBeNull();
    });

    test('saving a draft again overwrites it and never duplicates', function () {
        $this->settings->saveDraft($this->merchant->merchant_id, 5, 'k', ['a' => 1]);
        $this->settings->saveDraft($this->merchant->merchant_id, 5, 'k', ['a' => 2]);

        expect(FormDraft::withoutGlobalScopes()->count())->toBe(1)->and($this->settings->draft($this->merchant->merchant_id, 5, 'k')->payload)->toBe(['a' => 2]);
    });

    test('a draft of one store is never visible to another', function () {
        $this->settings->saveDraft($this->merchant->merchant_id, 5, 'k', ['secret' => 'mine']);

        expect($this->settings->draft($this->other->merchant_id, 5, 'k'))->toBeNull();
    });

    test('discarding removes only that draft', function () {
        $this->settings->saveDraft($this->merchant->merchant_id, 5, 'a', ['x' => 1]);
        $this->settings->saveDraft($this->merchant->merchant_id, 5, 'b', ['x' => 2]);

        $this->settings->discardDraft($this->merchant->merchant_id, 5, 'a');

        expect($this->settings->draft($this->merchant->merchant_id, 5, 'a'))->toBeNull()->and($this->settings->draft($this->merchant->merchant_id, 5, 'b'))->not->toBeNull();
    });

    test('drafts without a Salla user id are supported', function () {
        $this->settings->saveDraft($this->merchant->merchant_id, null, 'k', ['x' => 1]);

        expect($this->settings->draft($this->merchant->merchant_id, null, 'k')->payload)->toBe(['x' => 1]);
    });
});

describe('snapshot', function () {
    test('lists the dropdown options managed by super admins with both labels', function () {
        $options = $this->settings->snapshot($this->merchant)['options'];

        expect($options)->toHaveKeys(['industry', 'audience', 'brand_tone', 'photography_style'])
            ->and(collect($options['industry'])->firstWhere('key', 'fashion'))->toBe(['key' => 'fashion', 'label_ar' => 'أزياء', 'label_en' => 'Fashion']);
    });

    test('a new option appears in merchants\' Settings straight away', function () {
        ContextOption::create(['field' => 'industry', 'key' => 'pets', 'label_ar' => 'حيوانات', 'label_en' => 'Pets', 'sort' => 99, 'active' => true]);

        expect(collect($this->settings->snapshot($this->merchant)['options']['industry'])->pluck('key')->all())->toContain('pets');
    });
});
