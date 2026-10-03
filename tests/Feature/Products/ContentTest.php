<?php

use App\Billing\Exceptions\InsufficientCredits;
use App\Billing\WalletService;
use App\Models\AiModel;
use App\Models\BulkJob;
use App\Models\ContentGeneration;
use App\Models\ContentVersion;
use App\Models\CreditTransaction;
use App\Models\Merchant;
use App\Models\MerchantToken;
use App\Models\Plan;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductImageAlt;
use App\Models\ProductTranslation;
use App\Models\UsageLedger;
use App\Products\BulkContentService;
use App\Products\ContentGenerationService;
use App\Products\ContentReviewService;
use App\Products\Exceptions\OutdatedDraft;
use App\Products\Exceptions\PlanLocked;
use App\Products\Exceptions\PushFailed;
use App\Products\Exceptions\ReviewRejected;
use App\Products\HtmlSanitizer;
use App\Products\ProductPushService;
use App\Products\RunContentGeneration;
use App\Products\SlugGenerator;
use App\Settings\SettingsService;
use App\Support\CurrentMerchant;
use Database\Seeders\ModerationCategorySeeder;
use Database\Seeders\PromptDefaultSeeder;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    $this->seed([PromptDefaultSeeder::class, ModerationCategorySeeder::class]);
    Plan::factory()->create(['slug' => 'plus', 'feature_flags' => ['product_content' => true, 'image_edit' => true]]);
    AiModel::factory()->defaultFor('text_moderation')->create(['features' => ['text_moderation'], 'provider_model_id' => 'mod.text']);
    $this->model = AiModel::factory()->defaultFor('product_content')->create(['features' => ['product_content', 'field_regeneration'], 'provider_model_id' => 'text.content']);
    $this->merchant = Merchant::factory()->active()->create(['plan_code' => 'plus', 'plan_status' => 'active', 'default_language' => 'ar', 'enabled_languages' => ['ar', 'en']]);
    MerchantToken::factory()->create(['merchant_id' => $this->merchant->merchant_id]);
    $this->product = Product::factory()->create(['merchant_id' => $this->merchant->merchant_id, 'salla_product_id' => 777, 'relevant_hash' => 'h1']);
    ProductTranslation::factory()->create(['product_id' => $this->product->id, 'merchant_id' => $this->merchant->merchant_id, 'lang' => 'ar', 'name' => 'قديم', 'description' => '<p>قديم</p>']);
    app(WalletService::class)->credit($this->merchant->merchant_id, 100, CreditTransaction::MANUAL_GRANT);
    $this->answer = ['name' => 'فستان سهرة', 'description' => '<p>وصف <script>x()</script></p>', 'metadata_title' => 'عنوان', 'metadata_url' => 'Evening Dress!'];
    $this->fakeText->using(fn ($request) => isset($request->tool['schema']['properties']['blocked']) ? ['blocked' => false, 'category' => null] : array_intersect_key($this->answer, $request->tool['schema']['properties']));
    $this->service = app(ContentGenerationService::class);
    $this->review = app(ContentReviewService::class);
    $this->wallet = fn () => app(WalletService::class)->walletFor($this->merchant->merchant_id)->fresh();
});

function generate(array $fields = ['name', 'description', 'metadata_title', 'metadata_url'], string $lang = 'ar', array $options = []): ContentGeneration
{
    $service = app(ContentGenerationService::class);
    $generation = $service->request(test()->merchant, test()->product, $lang, $fields, 7, [...$options, 'dispatch' => false]);

    return $service->process($generation);
}

describe('sanitizer and slugs', function () {
    test('only allowed tags survive, attributes and scripts are removed, Arabic gets the RTL class', function () {
        $html = '<h2 onclick="x()">Head</h2><p style="color:red">Hi <a href="http://x">link</a><script>alert(1)</script><strong>b</strong></p><iframe src="x"></iframe><ul><li>1</li></ul><img src=x onerror=y>';

        $ar = app(HtmlSanitizer::class)->sanitize($html, 'ar');
        $en = app(HtmlSanitizer::class)->sanitize($html, 'en');

        expect($ar)->toBe('<h2 class="ql-direction-rtl">Head</h2><p class="ql-direction-rtl">Hi link<strong>b</strong></p><ul><li>1</li></ul>')
            ->and($en)->toBe('<h2>Head</h2><p>Hi link<strong>b</strong></p><ul><li>1</li></ul>');
    });

    test('plain text is wrapped in a paragraph and empty input stays empty', function () {
        expect(app(HtmlSanitizer::class)->sanitize('just text', 'en'))->toBe('<p>just text</p>')
            ->and(app(HtmlSanitizer::class)->sanitize('  ', 'en'))->toBe('')
            ->and(app(HtmlSanitizer::class)->sanitize('<script>x</script>', 'en'))->toBe('');
    });

    test('slugs have no spaces, are capped at 100 characters, and Arabic keeps its letters', function () {
        $slugs = app(SlugGenerator::class);

        expect($slugs->make('Evening Dress & Co!', 'en'))->toBe('evening-dress-co')
            ->and($slugs->make('فستان سهرة أنيق', 'ar'))->toBe('فستان-سهرة-أنيق')
            ->and(mb_strlen($slugs->make(str_repeat('word ', 60), 'en')))->toBeLessThanOrEqual(100)
            ->and($slugs->make(str_repeat('word ', 60), 'en'))->not->toEndWith('-');
    });
});

describe('generation', function () {
    test('a successful run saves a draft, sanitizes, captures 8 credits and writes usage', function () {
        $generation = generate();

        expect($generation)->status->toBe(ContentGeneration::DRAFT)->credits->toBe(8)->expires_at->not->toBeNull()
            ->and($generation->output)->toMatchArray(['name' => 'فستان سهرة', 'description' => '<p class="ql-direction-rtl">وصف </p>', 'metadata_url' => 'evening-dress'])
            ->and(($this->wallet)())->balance->toBe(92)->reserved->toBe(0)
            ->and(UsageLedger::where('feature', 'product_content')->where('internal', false)->sum('credits_charged'))->toBe(8)
            ->and(Product::find($this->product->id)->translation('ar')->name)->toBe('قديم');
    });

    test('the pushed Salla value is untouched until approval', function () {
        generate();

        expect(ProductTranslation::where('lang', 'ar')->sole()->name)->toBe('قديم');
        Http::assertNothingSent();
    });

    test('every call is sent a composed server-side prompt with limits and the platform rules', function () {
        generate(['name', 'metadata_title']);

        $system = collect($this->fakeText->requests)->first(fn ($request) => ! isset($request->tool['schema']['properties']['blocked']))->system;
        expect($system)->toContain('name ≤ 100')->toContain('metadata_title ≤ 100')->toContain(config('revo.moderation_platform_rules'));
    });

    test('a store without the plan feature is refused before any charge', function () {
        $this->merchant->update(['plan_status' => 'inactive']);

        expect(fn () => $this->service->request($this->merchant->fresh(), $this->product, 'ar', ['name'], 1))->toThrow(PlanLocked::class)
            ->and(($this->wallet)())->balance->toBe(100);
    });

    test('too little credit refuses the request and starts nothing', function () {
        app(WalletService::class)->deduct($this->merchant->merchant_id, 95, 'test');

        expect(fn () => $this->service->request($this->merchant, $this->product, 'ar', ['name'], 1, ['dispatch' => false]))->toThrow(InsufficientCredits::class)
            ->and(ContentGeneration::count())->toBe(0);
    });

    test('invalid language and field choices are refused', function (string $lang, array $fields) {
        expect(fn () => $this->service->request($this->merchant, $this->product, $lang, $fields, 1, ['dispatch' => false]))->toThrow(ReviewRejected::class);
    })->with([['fr', ['name']], ['ar', []], ['ar', ['bogus']]]);

    test('provider failure releases the credits and records no draft', function () {
        $this->fakeText->queue(new RuntimeException('boom'), new RuntimeException('boom'), new RuntimeException('boom'), new RuntimeException('boom'));

        $generation = generate(['name']);

        expect($generation)->status->toBe(ContentGeneration::FAILED)->credits->toBe(0)
            ->and(($this->wallet)())->balance->toBe(100)->reserved->toBe(0);
    });

    test('blocked keywords stop the run and the credits come back', function () {
        $this->fakeText->using(fn ($request) => isset($request->tool['schema']['properties']['blocked']) ? ['blocked' => true, 'category' => 'violence'] : $this->answer);

        $generation = generate(['name'], options: ['keywords' => 'gore']);

        expect($generation)->status->toBe(ContentGeneration::BLOCKED)->and(($this->wallet)())->balance->toBe(100);
    });

    test('an over-limit field is retried once and a fitting answer is used', function () {
        $calls = 0;
        $this->fakeText->using(function ($request) use (&$calls) {
            if (isset($request->tool['schema']['properties']['blocked'])) {
                return ['blocked' => false, 'category' => null];
            }
            $calls++;

            return ['metadata_title' => $calls === 1 ? str_repeat('x', 120) : 'short'];
        });

        $generation = generate(['metadata_title']);

        expect($generation->output['metadata_title'])->toBe('short')->and($generation->manual_fields)->toBeNull()->and($calls)->toBe(2);
    });

    test('a field still over its limit after the retry is flagged for manual edit and the run is still charged', function () {
        $this->fakeText->using(fn ($request) => isset($request->tool['schema']['properties']['blocked']) ? ['blocked' => false, 'category' => null] : ['metadata_title' => str_repeat('x', 120)]);

        $generation = generate(['metadata_title']);

        expect($generation)->status->toBe(ContentGeneration::DRAFT)->manual_fields->toBe(['metadata_title'])->credits->toBe(8)
            ->and(($this->wallet)())->balance->toBe(92);
    });

    test('field regeneration costs 2 credits and one field only', function () {
        $generation = generate(['name'], options: ['kind' => 'field']);

        expect($generation)->kind->toBe('field')->credits->toBe(2)->and(($this->wallet)())->balance->toBe(98);
        expect(fn () => $this->service->request($this->merchant, $this->product, 'ar', ['name', 'description'], 1, ['kind' => 'field', 'dispatch' => false]))->toThrow(ReviewRejected::class);
    });

    test('the quote shows the price before running', function () {
        expect($this->service->quote($this->merchant))->toMatchArray(['credits' => 8])
            ->and($this->service->quote($this->merchant, 'field'))->toMatchArray(['credits' => 2]);
    });

    test('alt text is stored per image and language, not pushed', function () {
        $image = ProductImage::factory()->create(['product_id' => $this->product->id, 'merchant_id' => $this->merchant->merchant_id, 'salla_image_id' => 55]);
        $this->answer = ['alt' => [['image_id' => 55, 'alt' => 'حذاء رياضي'], ['image_id' => 999, 'alt' => 'ignored']]];

        generate(['alt']);

        expect(ProductImageAlt::sole())->product_image_id->toBe($image->id)->lang->toBe('ar')->alt->toBe('حذاء رياضي');
        Http::assertNothingSent();
    });

    test('a queued generation is run by the job once', function () {
        $generation = $this->service->request($this->merchant, $this->product, 'ar', ['name'], 1, ['dispatch' => false]);

        RunContentGeneration::dispatchSync($this->merchant->merchant_id, $generation->id);
        RunContentGeneration::dispatchSync($this->merchant->merchant_id, $generation->id);

        expect($generation->fresh()->status)->toBe(ContentGeneration::DRAFT)->and(UsageLedger::where('internal', false)->count())->toBe(1);
    });
});

describe('review and push', function () {
    beforeEach(function () {
        Http::fake(['api.salla.dev/admin/v2/products/777' => Http::response(['data' => ['id' => 777]])]);
    });

    test('approving pushes only the approved fields, with SEO nested under metadata, in the draft language', function () {
        $generation = generate(['name', 'metadata_title']);

        $this->review->approve($generation, ['metadata_title'], merchant: $this->merchant);

        Http::assertSent(fn (Request $r) => $r->method() === 'PUT' && $r->hasHeader('Accept-Language', 'ar') && $r->data() === ['metadata' => ['title' => 'عنوان']]);
        expect(ProductTranslation::where('lang', 'ar')->sole())->metadata_title->toBe('عنوان')->name->toBe('قديم')
            ->and($generation->fresh()->status)->toBe(ContentGeneration::DRAFT)
            ->and($this->review->pendingFields($generation->fresh()))->toBe(['name']);
    });

    test('approving everything completes the draft and keeps history with the Salla original', function () {
        $generation = generate(['name']);

        $done = $this->review->approve($generation, merchant: $this->merchant, sallaUserId: 7);

        expect($done)->status->toBe(ContentGeneration::APPROVED)->approved_at->not->toBeNull()
            ->and(ContentVersion::orderBy('id')->pluck('source')->all())->toBe(['salla_original', 'ai'])
            ->and(ContentVersion::where('source', 'salla_original')->value('value'))->toBe('قديم');
        Http::assertSent(fn (Request $r) => $r->data() === ['name' => 'فستان سهرة']);
    });

    test('edits are sanitized, recorded as manual edits, and limits are enforced', function () {
        $generation = generate(['name', 'metadata_title']);

        $this->review->approve($generation, ['name'], ['name' => '  <b>My edit</b> '], $this->merchant);
        expect(ContentVersion::where('field', 'name')->orderByDesc('id')->first())->source->toBe('manual_edit')->value->toBe('My edit');

        expect(fn () => $this->review->approve($generation->fresh(), ['metadata_title'], ['metadata_title' => str_repeat('x', 101)], $this->merchant))
            ->toThrow(ReviewRejected::class);
    });

    test('a flagged field cannot be approved unedited', function () {
        $this->fakeText->using(fn ($request) => isset($request->tool['schema']['properties']['blocked']) ? ['blocked' => false, 'category' => null] : ['metadata_title' => str_repeat('x', 120)]);
        $generation = generate(['metadata_title']);

        expect(fn () => $this->review->approve($generation, merchant: $this->merchant))->toThrow(ReviewRejected::class);

        $this->review->approve($generation, edits: ['metadata_title' => 'fits'], merchant: $this->merchant);
        expect(ProductTranslation::where('lang', 'ar')->sole()->metadata_title)->toBe('fits');
    });

    test('blocked edits are refused and nothing is pushed', function () {
        $generation = generate(['name']);
        $this->fakeText->using(fn ($request) => ['blocked' => true, 'category' => 'violence']);

        expect(fn () => $this->review->approve($generation, edits: ['name' => 'bad'], merchant: $this->merchant))->toThrow(ReviewRejected::class);
        Http::assertNothingSent();
    });

    test('rejecting keeps the charge and cannot be repeated', function () {
        $generation = generate(['name']);

        $this->review->reject($generation);

        expect($generation->fresh()->status)->toBe(ContentGeneration::REJECTED)->and(($this->wallet)())->balance->toBe(92)
            ->and(fn () => $this->review->approve($generation->fresh(), merchant: $this->merchant))->toThrow(ReviewRejected::class);
    });

    test('a product that changed in Salla needs an explicit acknowledgement', function () {
        $generation = generate(['name']);
        $this->product->update(['relevant_hash' => 'h2']);

        expect(fn () => $this->review->approve($generation, merchant: $this->merchant))->toThrow(OutdatedDraft::class);
        $this->review->approve($generation, merchant: $this->merchant, acknowledgeOutdated: true);

        expect($generation->fresh()->status)->toBe(ContentGeneration::APPROVED);
    });

    test('a failed push keeps the draft, charges nothing more and can be retried', function () {
        $generation = generate(['name']);
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(['api.salla.dev/admin/v2/products/777' => Http::sequence()->push('bad', 422)->push(['data' => []])]);

        expect(fn () => $this->review->approve($generation, merchant: $this->merchant))->toThrow(PushFailed::class);
        expect($generation->fresh()->status)->toBe(ContentGeneration::DRAFT)->and(ProductTranslation::where('lang', 'ar')->sole()->name)->toBe('قديم')
            ->and(($this->wallet)())->balance->toBe(92);

        $this->review->approve($generation->fresh(), merchant: $this->merchant);
        expect($generation->fresh()->status)->toBe(ContentGeneration::APPROVED)->and(($this->wallet)())->balance->toBe(92);
    });

    test('approving alt text marks it done without a push', function () {
        ProductImage::factory()->create(['product_id' => $this->product->id, 'merchant_id' => $this->merchant->merchant_id, 'salla_image_id' => 55]);
        $this->answer = ['alt' => [['image_id' => 55, 'alt' => 'alt']]];
        $generation = generate(['alt']);

        $this->review->approve($generation, merchant: $this->merchant);

        expect($generation->fresh()->status)->toBe(ContentGeneration::APPROVED);
        Http::assertNothingSent();
    });

    test('auto-publish pushes without a review step', function () {
        app(SettingsService::class)->updateGeneral($this->merchant, ['auto_publish' => true], 1);

        $generation = generate(['name']);

        expect($generation->status)->toBe(ContentGeneration::APPROVED)->and(ProductTranslation::where('lang', 'ar')->sole()->name)->toBe('فستان سهرة');
    });

    test('revert pushes the earlier value and records a revert', function () {
        $generation = generate(['name']);
        $this->review->approve($generation, merchant: $this->merchant);
        $original = ContentVersion::where('source', 'salla_original')->sole();

        app(ProductPushService::class)->revert($this->merchant, $original, 7);

        Http::assertSent(fn (Request $r) => $r->data() === ['name' => 'قديم']);
        expect(ProductTranslation::where('lang', 'ar')->sole()->name)->toBe('قديم')->and(ContentVersion::latest('id')->first()->source)->toBe('revert');
    });

    test('expired drafts are deleted, fresh ones stay', function () {
        $old = generate(['name']);
        $fresh = generate(['name']);
        $old->update(['expires_at' => now()->subMinute()]);

        expect($this->review->pruneExpired())->toBe(1)->and(ContentGeneration::pluck('id')->all())->toBe([$fresh->id]);
    });

    test('drafts of one store are invisible to another', function () {
        generate(['name']);
        $other = Merchant::factory()->active()->create();
        app(CurrentMerchant::class)->set($other->merchant_id);

        expect(ContentGeneration::count())->toBe(0);
    });
});

describe('bulk', function () {
    beforeEach(function () {
        $this->bulk = app(BulkContentService::class);
        $this->all = ['type' => 'all'];
    });

    function seedProducts(int $count): void
    {
        Product::factory()->count($count)->create(['merchant_id' => test()->merchant->merchant_id]);
    }

    test('the estimate multiplies products by languages by the unit price', function () {
        seedProducts(11);

        expect($this->bulk->estimate($this->merchant, $this->all, ['ar', 'en']))->toMatchArray(['products' => 12, 'units' => 24, 'unit_price' => 8, 'estimate' => 192, 'available' => 100]);
    });

    test('120 products in two languages need 1,920 credits and are refused with 1,900', function () {
        seedProducts(119);
        app(WalletService::class)->credit($this->merchant->merchant_id, 1800, CreditTransaction::MANUAL_GRANT);

        expect($this->bulk->estimate($this->merchant, $this->all, ['ar', 'en'])['estimate'])->toBe(1920)
            ->and(fn () => $this->bulk->start($this->merchant, $this->all, ['name'], ['ar', 'en']))->toThrow(InsufficientCredits::class)
            ->and(ContentGeneration::count())->toBe(0)->and(BulkJob::count())->toBe(0)->and(($this->wallet)())->reserved->toBe(0);
    });

    test('a started job reserves the whole estimate and every unit completes', function () {
        seedProducts(2);

        $job = $this->bulk->start($this->merchant, $this->all, ['name'], ['ar', 'en'], 7);

        $job = $job->fresh();
        expect($job)->total->toBe(6)->estimate->toBe(48)->status->toBe('completed')->done->toBe(6)->failed->toBe(0)
            ->and(($this->wallet)())->balance->toBe(52)->reserved->toBe(0)
            ->and(ContentGeneration::where('bulk_job_id', $job->id)->where('status', 'draft')->count())->toBe(6);
    });

    test('canceling releases the units that never started and keeps finished ones charged', function () {
        seedProducts(2);
        Queue::fake();
        $job = $this->bulk->start($this->merchant, $this->all, ['name'], ['ar']);
        $first = ContentGeneration::where('bulk_job_id', $job->id)->orderBy('id')->first();
        $this->service->process($first);

        $canceled = $this->bulk->cancel($job);

        expect($canceled)->status->toBe('canceled')->done->toBe(1)
            ->and(ContentGeneration::where('status', 'canceled')->count())->toBe(2)
            ->and(($this->wallet)())->balance->toBe(92)->reserved->toBe(0);
    });

    test('failed units release their credits and are counted', function () {
        seedProducts(1);
        $this->fakeText->using(fn ($request) => isset($request->tool['schema']['properties']['blocked']) ? ['blocked' => false, 'category' => null] : throw new RuntimeException('boom'));

        $job = $this->bulk->start($this->merchant, ['type' => 'ids', 'ids' => [Product::latest('id')->first()->id]], ['name'], ['ar'])->fresh();

        expect($job)->failed->toBe(1)->done->toBe(0)->status->toBe('completed')->and(($this->wallet)())->balance->toBe(100);
    });

    test('empty selections and unknown languages are refused', function () {
        expect(fn () => $this->bulk->start($this->merchant, ['type' => 'ids', 'ids' => [999999]], ['name'], ['ar']))->toThrow(ReviewRejected::class)
            ->and(fn () => $this->bulk->start($this->merchant, $this->all, ['name'], ['fr']))->toThrow(ReviewRejected::class);
    });

    test('the missing-SEO filter selects only products without an SEO title', function () {
        ProductTranslation::where('lang', 'ar')->update(['metadata_title' => 'has seo']);
        seedProducts(2);

        expect($this->bulk->estimate($this->merchant, ['type' => 'missing_seo'], ['ar'])['products'])->toBe(2);
    });
});
