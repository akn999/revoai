<?php

use App\Ai\AiContext;
use App\Ai\AiGateway;
use App\Ai\Dto\ImageEditRequest;
use App\Ai\Dto\TextRequest;
use App\Ai\Dto\TextResponse;
use App\Ai\Exceptions\AiUnavailable;
use App\Ai\Exceptions\InvalidStructuredOutput;
use App\Ai\Middleware\LimitAiJobs;
use App\Ai\ModelResolver;
use App\Ai\PriceResolver;
use App\Models\AiModel;
use App\Models\Preset;
use App\Models\UsageLedger;
use App\Platform\RevoSettings;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;

beforeEach(function () {
    $this->gateway = app(AiGateway::class);
    $this->context = new AiContext(5001, 'product_content', 'product_content', 9, internal: false, credits: 8);
    $this->text = AiModel::factory()->create([
        'provider_model_id' => 'text.model-a',
        'cost_rates' => ['input_per_1k' => 0.003, 'output_per_1k' => 0.015],
    ]);
    $this->request = new TextRequest('text.model-a', 'system prompt', [['role' => 'user', 'content' => 'hello']]);
});

describe('usage ledger', function () {
    test('a text call writes one row with tokens, cost, model and credits', function () {
        $this->fakeText->queue(new TextResponse('hi', null, 2000, 1000, 'end_turn', 'req-1'));

        $response = $this->gateway->converse($this->context, $this->text, $this->request);

        $row = UsageLedger::sole();
        expect($response->text)->toBe('hi')
            ->and($row)
            ->merchant_id->toBe(5001)->salla_user_id->toBe(9)->feature->toBe('product_content')->action->toBe('product_content')
            ->provider->toBe('bedrock')->ai_model_id->toBe($this->text->id)->provider_model_id->toBe('text.model-a')
            ->provider_request_id->toBe('req-1')->input_tokens->toBe(2000)->output_tokens->toBe(1000)->images->toBe(0)
            ->credits_charged->toBe(8)->internal->toBeFalse()->status->toBe('ok')->latency_ms->toBeInt()
            ->and((float) $row->provider_cost_usd)->toBe(0.021);
    });

    test('uncharged internal calls are recorded with zero credits', function () {
        $internal = new AiContext(5001, 'product_content', 'product_context', null);

        $this->gateway->converse($internal, $this->text, $this->request);

        expect(UsageLedger::sole())->internal->toBeTrue()->credits_charged->toBe(0)->salla_user_id->toBeNull();
    });

    test('prompts and outputs are never stored in the ledger', function () {
        $this->fakeText->queue(new TextResponse('a very private product description', null, 10, 10));

        $this->gateway->converse($this->context, $this->text, new TextRequest('text.model-a', 'secret system prompt', [['role' => 'user', 'content' => 'private input']]));

        $dump = json_encode(UsageLedger::sole()->toArray());
        expect($dump)->not->toContain('private')->not->toContain('secret system prompt');
    });

    test('a failed provider call is recorded as an error with no charge and a merchant-safe message', function () {
        $this->fakeText->queue(new RuntimeException('Throttling: internal AWS detail arn:aws:secret'));

        try {
            $this->gateway->converse($this->context, $this->text, $this->request);
            $this->fail('expected AiUnavailable');
        } catch (AiUnavailable $exception) {
            expect($exception->getMessage())->toBe('The AI service is busy, try again')->not->toContain('arn:aws');
        }

        expect(UsageLedger::sole())->status->toBe('error')->credits_charged->toBe(0);
    });

    test('image analysis and moderation rows count one image each', function () {
        $analysis = AiModel::factory()->image()->create(['features' => ['image_analysis'], 'cost_rates' => ['per_image' => 0.005]]);
        $moderation = AiModel::factory()->image()->create(['features' => ['image_moderation'], 'cost_rates' => ['per_image' => 0.002]]);
        $context = new AiContext(5001, 'image_edit', 'image_analysis');

        $this->gateway->analyzeImage($context, $analysis, 'https://cdn.salla.sa/a.png', 'Describe');
        $this->gateway->moderateImage($context->for('image_edit', 'image_moderation'), $moderation, 'https://cdn.salla.sa/a.png', ['nudity' => 'x']);

        expect(UsageLedger::orderBy('id')->get()->map->only(['images', 'provider'])->all())->toBe([
            ['images' => 1, 'provider' => 'fal'], ['images' => 1, 'provider' => 'fal'],
        ]);
    });

    test('a blocked image is recorded with its own status', function () {
        $moderation = AiModel::factory()->image()->create(['features' => ['image_moderation']]);
        $this->fakeImage->moderationRule = fn () => 'weapons';

        $this->gateway->moderateImage(new AiContext(5001, 'image_edit', 'image_moderation'), $moderation, 'https://cdn.salla.sa/a.png', ['weapons' => 'x']);

        expect(UsageLedger::sole()->status)->toBe('blocked');
    });

    test('an image edit is submitted, polled and recorded with the real image count', function () {
        $editor = AiModel::factory()->image()->create(['provider_model_id' => 'fal/editor', 'cost_rates' => ['per_image' => 0.04]]);
        $context = new AiContext(5001, 'image_edit', 'image_edit', 9, internal: false, credits: 20);

        $requestId = $this->gateway->submitImageEdit($context, $editor, new ImageEditRequest('fal/editor', 'https://cdn.salla.sa/a.png', 'white bg', [], 3));
        expect($this->gateway->imageStatus($editor, $requestId)->state)->toBe('completed');
        $result = $this->gateway->imageResult($context, $editor, $requestId);

        expect($result->urls)->toHaveCount(3)
            ->and(UsageLedger::sole())->images->toBe(3)->credits_charged->toBe(20)->provider_request_id->toBe($requestId)
            ->and((float) UsageLedger::sole()->provider_cost_usd)->toBe(0.12);
    });

    test('the ledger is append-only', function () {
        $this->gateway->converse($this->context, $this->text, $this->request);

        expect(fn () => UsageLedger::sole()->update(['credits_charged' => 0]))->toThrow(LogicException::class);
    });
});

describe('structured output', function () {
    $schema = ['type' => 'object', 'properties' => ['name' => ['type' => 'string']], 'required' => ['name']];

    test('a tool-capable model is forced to answer through the tool', function () use ($schema) {
        $this->fakeText->using(fn () => ['name' => 'Blue shirt']);

        $data = $this->gateway->structured($this->context, $this->text, $this->request, $schema);

        $sent = $this->fakeText->lastRequest();
        expect($data)->toBe(['name' => 'Blue shirt'])
            ->and($sent->tool['name'])->toBe('submit_result')->and($sent->tool['schema'])->toBe($schema);
    });

    test('a model without tool use is asked for strict JSON instead', function () use ($schema) {
        $plain = AiModel::factory()->create(['provider_model_id' => 'plain.model', 'capabilities' => ['tool_use' => false]]);
        $this->fakeText->queue(new TextResponse('{"name":"Blue shirt"}', null, 10, 5));

        $data = $this->gateway->structured($this->context, $plain, $this->request, $schema);

        $sent = $this->fakeText->lastRequest();
        expect($data)->toBe(['name' => 'Blue shirt'])->and($sent->tool)->toBeNull()->and($sent->system)->toContain('single JSON object');
    });

    test('malformed JSON is retried once and then succeeds', function () use ($schema) {
        $plain = AiModel::factory()->create(['capabilities' => ['tool_use' => false]]);
        $this->fakeText->queue(new TextResponse('not json {', null, 10, 5), new TextResponse('{"name":"ok"}', null, 10, 5));

        expect($this->gateway->structured($this->context, $plain, $this->request, $schema))->toBe(['name' => 'ok'])
            ->and(count($this->fakeText->requests))->toBe(2)->and(UsageLedger::count())->toBe(2);
    });

    test('malformed output twice fails cleanly after exactly two calls', function () use ($schema) {
        $plain = AiModel::factory()->create(['capabilities' => ['tool_use' => false]]);
        $this->fakeText->queue(new TextResponse('nope', null, 10, 5), new TextResponse('still nope', null, 10, 5));

        expect(fn () => $this->gateway->structured($this->context, $plain, $this->request, $schema))->toThrow(InvalidStructuredOutput::class)
            ->and(count($this->fakeText->requests))->toBe(2);
    });

    test('a missing tool answer is retried once too', function () use ($schema) {
        $this->fakeText->queue(new TextResponse('text instead', null, 10, 5), new TextResponse('', ['name' => 'fixed'], 10, 5, 'tool_use'));

        expect($this->gateway->structured($this->context, $this->text, $this->request, $schema))->toBe(['name' => 'fixed']);
    });
});

describe('price resolution', function () {
    beforeEach(fn () => $this->prices = app(PriceResolver::class));

    test('the default price is used when nothing overrides it', function () {
        expect($this->prices->resolve('image_edit'))->toBe(['credits' => 20, 'source' => 'default'])
            ->and($this->prices->resolve('field_regeneration'))->toBe(['credits' => 2, 'source' => 'default']);
    });

    test('the model price beats the default', function () {
        $model = AiModel::factory()->create(['prices' => ['product_content' => 12]]);

        expect($this->prices->resolve('product_content', $model))->toBe(['credits' => 12, 'source' => 'model']);
    });

    test('the preset price beats the model price for image edits only', function () {
        $model = AiModel::factory()->image()->create(['prices' => ['image_edit' => 25]]);
        $preset = Preset::factory()->create(['price_override' => 15]);

        expect($this->prices->resolve('image_edit', $model, $preset))->toBe(['credits' => 15, 'source' => 'preset'])
            ->and($this->prices->resolve('product_content', $model, $preset)['source'])->toBe('default');
    });

    test('a zero price is a real price and not "missing"', function () {
        $model = AiModel::factory()->create(['prices' => ['product_content' => 0]]);
        $preset = Preset::factory()->create(['price_override' => 0]);

        expect($this->prices->resolve('product_content', $model)['credits'])->toBe(0)
            ->and($this->prices->resolve('image_edit', null, $preset))->toBe(['credits' => 0, 'source' => 'preset']);
    });

    test('an admin changing the default applies to the next resolution', function () {
        app(RevoSettings::class)->set('prices.image_edit', 25);

        expect($this->prices->resolve('image_edit')['credits'])->toBe(25);
    });
});

describe('model resolution', function () {
    beforeEach(fn () => $this->resolver = app(ModelResolver::class));

    test('the merchant\'s active choice wins', function () {
        $default = AiModel::factory()->defaultFor('product_content')->create();
        $choice = AiModel::factory()->create();

        expect($this->resolver->resolve('product_content', $choice->id)->id)->toBe($choice->id)
            ->and($this->resolver->resolve('product_content')->id)->toBe($default->id);
    });

    test('a deactivated choice falls back to the feature default and says so', function () {
        $default = AiModel::factory()->defaultFor('product_content')->create();
        $choice = AiModel::factory()->inactive()->create();

        expect($this->resolver->resolve('product_content', $choice->id)->id)->toBe($default->id)
            ->and($this->resolver->selectionFellBack('product_content', $choice->id))->toBeTrue()
            ->and($this->resolver->selectionFellBack('product_content', $default->id))->toBeFalse()
            ->and($this->resolver->selectionFellBack('product_content', null))->toBeFalse();
    });

    test('a model not offered for the feature cannot be chosen', function () {
        $default = AiModel::factory()->defaultFor('image_edit')->image()->create();
        $wrong = AiModel::factory()->create();

        expect($this->resolver->resolve('image_edit', $wrong->id)->id)->toBe($default->id);
    });

    test('with no explicit default the first active model of the feature is used, and none gives null', function () {
        expect($this->resolver->resolve('chat'))->toBeNull();

        $first = AiModel::factory()->create(['features' => ['chat'], 'sort' => 0]);
        AiModel::factory()->create(['features' => ['chat'], 'sort' => 5]);

        expect($this->resolver->resolve('chat')->id)->toBe($first->id);
    });

    test('options list only active models of that feature in order', function () {
        AiModel::factory()->create(['features' => ['product_content'], 'sort' => 2, 'name_en' => 'B']);
        AiModel::factory()->create(['features' => ['product_content'], 'sort' => 1, 'name_en' => 'A']);
        AiModel::factory()->inactive()->create(['features' => ['product_content']]);
        AiModel::factory()->image()->create();

        expect($this->resolver->options('product_content')->pluck('name_en')->all())->toBe(['Test model', 'A', 'B']);
    });
});

describe('queue limits', function () {
    function runLimited(int $merchantId, ?Closure $inside = null): array
    {
        $job = new class
        {
            public ?int $releasedFor = null;

            public function release(int $seconds): void
            {
                $this->releasedFor = $seconds;
            }
        };
        $ran = false;
        (new LimitAiJobs($merchantId))->handle($job, function () use (&$ran, $inside) {
            $ran = true;
            $inside && $inside();
        });

        return ['ran' => $ran, 'released' => $job->releasedFor];
    }

    test('a free slot lets the job run and frees the slot afterwards', function () {
        expect(runLimited(1))->toBe(['ran' => true, 'released' => null])
            ->and(runLimited(1)['ran'])->toBeTrue();
    });

    test('at most three jobs of one store run at once; the fourth is released', function () {
        $result = null;
        runLimited(1, function () use (&$result) {
            runLimited(1, function () use (&$result) {
                runLimited(1, function () use (&$result) {
                    $result = runLimited(1);
                });
            });
        });

        expect($result)->toBe(['ran' => false, 'released' => 10]);
    });

    test('the concurrency limit is per store', function () {
        $other = null;
        runLimited(1, function () use (&$other) {
            runLimited(1, function () use (&$other) {
                runLimited(1, function () use (&$other) {
                    $other = runLimited(2);
                });
            });
        });

        expect($other['ran'])->toBeTrue();
    });

    test('the limit follows configuration', function () {
        config(['revo.limits.bulk_concurrency' => 1]);
        $result = null;

        runLimited(1, function () use (&$result) {
            $result = runLimited(1);
        });

        expect($result['ran'])->toBeFalse();
    });

    test('the platform-wide provider rate holds jobs back when exceeded', function () {
        config(['revo.limits.ai_global_rate_per_minute' => 2]);
        RateLimiter::clear('ai-global:ai');
        Cache::flush();

        expect(runLimited(1)['ran'])->toBeTrue()->and(runLimited(2)['ran'])->toBeTrue();
        $third = runLimited(3);

        expect($third['ran'])->toBeFalse()->and($third['released'])->toBeGreaterThan(0);
    });
});
