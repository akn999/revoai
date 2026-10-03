<?php

use App\Ai\AiContext;
use App\Ai\Dto\TextResponse;
use App\Models\AiModel;
use App\Models\ModerationCategory;
use App\Models\ModerationEvent;
use App\Models\UsageLedger;
use App\Moderation\ModerationBlocked;
use App\Moderation\ModerationService;
use App\Moderation\ModerationUnavailable;
use Database\Seeders\ModerationCategorySeeder;

beforeEach(function () {
    $this->seed(ModerationCategorySeeder::class);
    AiModel::factory()->defaultFor('text_moderation')->create(['features' => ['text_moderation'], 'provider_model_id' => 'moderator.text']);
    AiModel::factory()->image()->defaultFor('image_moderation')->create(['features' => ['image_moderation'], 'provider_model_id' => 'moderator.image']);
    $this->moderation = app(ModerationService::class);
    $this->context = new AiContext(4001, 'product_content', 'product_content', 77);
});

describe('text', function () {
    test('allowed text passes and the decision is logged', function () {
        $this->fakeText->using(fn () => ['blocked' => false, 'category' => null]);

        $this->moderation->checkText($this->context, 'A cotton shirt', 'edit_instruction', '12');

        expect(ModerationEvent::sole())
            ->merchant_id->toBe(4001)->salla_user_id->toBe(77)->subject_type->toBe('edit_instruction')->subject_id->toBe('12')
            ->decision->toBe('allowed')->provider->toBe('bedrock')->category->toBeNull();
    });

    test('blocked text raises the category name only and is logged with it', function () {
        $this->fakeText->using(fn () => ['blocked' => true, 'category' => 'violence']);

        try {
            $this->moderation->checkText($this->context, 'show gore', 'edit_instruction');
            $this->fail('expected a block');
        } catch (ModerationBlocked $blocked) {
            expect($blocked)->category->toBe('violence')->categoryName->toBe('Violence and gore')
                ->and($blocked->getMessage())->toBe('Blocked: Violence and gore');
        }

        expect(ModerationEvent::sole())->decision->toBe('blocked')->category->toBe('violence');
    });

    test('blank text is never sent to a provider', function (?string $text) {
        $this->moderation->checkText($this->context, $text, 'prompt');

        expect($this->fakeText->requests)->toBeEmpty()->and(ModerationEvent::count())->toBe(0);
    })->with([null, '', '   ']);

    test('the policy lists every active category with its allowed exceptions and nothing switched off', function () {
        ModerationCategory::where('key', 'alcohol')->update(['active' => false]);
        $this->fakeText->using(fn () => ['blocked' => false]);

        $this->moderation->checkText($this->context, 'hello', 'prompt');

        $system = $this->fakeText->lastRequest()->system;
        expect($system)->toContain('- weapons:')->toContain('Explicitly allowed: Legal products such as hunting gear')
            ->and($system)->not->toContain('- alcohol:')
            ->and($this->fakeText->lastRequest()->tool['schema']['properties']['category']['enum'])->not->toContain('alcohol');
    });

    test('switching a category off stops blocking it on the next request', function () {
        ModerationCategory::where('key', 'alcohol')->update(['active' => false]);
        $this->fakeText->using(fn () => ['blocked' => true, 'category' => 'alcohol']);

        // The model may still claim it; the service only trusts categories that are active, otherwise it reports a generic block.
        expect(fn () => $this->moderation->checkText($this->context, 'wine bottle', 'prompt'))->toThrow(ModerationBlocked::class);
    });

    test('a provider failure fails closed and is logged as an error', function () {
        $this->fakeText->queue(new RuntimeException('bedrock down'));

        expect(fn () => $this->moderation->checkText($this->context, 'anything', 'prompt'))->toThrow(ModerationUnavailable::class);

        expect(ModerationEvent::sole())->decision->toBe('error');
    });

    test('unreadable model output fails closed after one retry', function () {
        $this->fakeText->queue(new TextResponse('', null, 10, 5), new TextResponse('', null, 10, 5));

        expect(fn () => $this->moderation->checkText($this->context, 'anything', 'prompt'))->toThrow(ModerationUnavailable::class)
            ->and(count($this->fakeText->requests))->toBe(2);
    });

    test('a missing moderation model fails closed', function () {
        AiModel::query()->delete();

        expect(fn () => $this->moderation->checkText($this->context, 'anything', 'prompt'))->toThrow(ModerationUnavailable::class);
    });

    test('moderation calls are uncharged internal usage rows', function () {
        $this->fakeText->using(fn () => ['blocked' => false]);

        $this->moderation->checkText($this->context, 'hello', 'prompt');

        expect(UsageLedger::sole())->feature->toBe('text_moderation')->internal->toBeTrue()->credits_charged->toBe(0)->merchant_id->toBe(4001);
    });
});

describe('images', function () {
    test('an allowed image passes through the image provider with the active categories', function () {
        $this->moderation->checkImage($this->context, 'https://cdn.salla.sa/a.png', 'source_image', '5');

        expect($this->fakeImage->moderated)->toBe(['https://cdn.salla.sa/a.png'])
            ->and(ModerationEvent::sole())->provider->toBe('fal')->decision->toBe('allowed')
            ->and(UsageLedger::sole())->feature->toBe('image_moderation')->internal->toBeTrue();
    });

    test('a blocked image raises the category', function () {
        $this->fakeImage->moderationRule = fn (string $url) => str_contains($url, 'bad') ? 'nudity' : null;

        expect(fn () => $this->moderation->checkImage($this->context, 'https://cdn.salla.sa/bad.png', 'output_image'))
            ->toThrow(ModerationBlocked::class, 'Nudity and sexual content');
        expect(ModerationEvent::sole())->decision->toBe('blocked')->category->toBe('nudity');
    });

    test('a provider failure fails closed', function () {
        $this->fakeImage->moderationFails = new RuntimeException('fal down');

        expect(fn () => $this->moderation->checkImage($this->context, 'https://cdn.salla.sa/a.png', 'output_image'))
            ->toThrow(ModerationUnavailable::class);
        expect(ModerationEvent::sole())->decision->toBe('error')
            ->and(UsageLedger::sole())->status->toBe('error');
    });
});

describe('platform rules and decision log', function () {
    test('the modesty rule is part of the always-on platform rules', function () {
        expect($this->moderation->platformRules())->toContain('modestly dressed');
    });

    test('decisions of different stores stay separate', function () {
        $this->fakeText->using(fn () => ['blocked' => false]);

        $this->moderation->checkText(new AiContext(1, 'product_content', 'x'), 'a', 'prompt');
        $this->moderation->checkText(new AiContext(2, 'product_content', 'x'), 'b', 'prompt');

        expect(ModerationEvent::withoutGlobalScopes()->pluck('merchant_id')->all())->toBe([1, 2]);
    });
});
