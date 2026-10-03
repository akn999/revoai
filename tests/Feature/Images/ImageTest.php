<?php

use App\Ai\Dto\ImageJobStatus;
use App\Billing\Exceptions\InsufficientCredits;
use App\Billing\WalletService;
use App\Images\Exceptions\InvalidImage;
use App\Images\ImageEditService;
use App\Images\ImageSanitizer;
use App\Images\ImageStore;
use App\Images\MediaService;
use App\Images\PollImageEdit;
use App\Images\SubmitImageEdit;
use App\Mail\ImageExpiryDigest;
use App\Models\AiModel;
use App\Models\CreditTransaction;
use App\Models\GeneratedImage;
use App\Models\ImageAnalysis;
use App\Models\ImageGeneration;
use App\Models\MediaFolder;
use App\Models\Merchant;
use App\Models\MerchantToken;
use App\Models\ModerationEvent;
use App\Models\Plan;
use App\Models\Preset;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\UsageLedger;
use App\Moderation\ModerationBlocked;
use App\Products\Exceptions\PlanLocked;
use App\Products\Exceptions\ReviewRejected;
use App\Support\CurrentMerchant;
use Database\Seeders\ModerationCategorySeeder;
use Database\Seeders\PromptDefaultSeeder;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

function png(): string
{
    return base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
}

function pngWithText(): string
{
    $png = png();
    $text = 'secret-gps-data';
    $chunk = pack('N', strlen("Comment\0{$text}")).'tEXt'."Comment\0{$text}".pack('N', crc32('tEXt'."Comment\0{$text}"));

    return substr($png, 0, 33).$chunk.substr($png, 33);
}

beforeEach(function () {
    Storage::fake('local');
    config(['revo.images.disk' => 'local']);
    $this->seed([PromptDefaultSeeder::class, ModerationCategorySeeder::class]);
    Plan::factory()->create(['slug' => 'plus', 'feature_flags' => ['product_content' => true, 'image_edit' => true]]);
    AiModel::factory()->defaultFor('text_moderation')->create(['features' => ['text_moderation']]);
    AiModel::factory()->image()->defaultFor('image_moderation')->create(['features' => ['image_moderation']]);
    AiModel::factory()->image()->defaultFor('image_analysis')->create(['features' => ['image_analysis']]);
    $this->model = AiModel::factory()->image()->defaultFor('image_edit')->create(['features' => ['image_edit'], 'provider_model_id' => 'fal/edit']);
    $this->fakeText->using(fn () => ['blocked' => false, 'category' => null]);
    $this->merchant = Merchant::factory()->active()->create(['plan_code' => 'plus', 'plan_status' => 'active', 'email' => 'shop@example.com']);
    MerchantToken::factory()->create(['merchant_id' => $this->merchant->merchant_id]);
    app(WalletService::class)->credit($this->merchant->merchant_id, 100, CreditTransaction::MANUAL_GRANT);
    $this->product = Product::factory()->create(['merchant_id' => $this->merchant->merchant_id, 'salla_product_id' => 777]);
    $this->source = ProductImage::factory()->create(['product_id' => $this->product->id, 'merchant_id' => $this->merchant->merchant_id, 'salla_image_id' => 5, 'url' => 'https://cdn.salla.sa/p/5.jpg']);
    $this->images = app(ImageEditService::class);
    $this->wallet = fn () => app(WalletService::class)->walletFor($this->merchant->merchant_id)->fresh();
    Http::fake(['v3.fal.media/*' => Http::response(png(), 200, ['Content-Type' => 'image/png'])]);
});

function runEdit(array $options = ['instruction' => 'white background'], bool $poll = true): ImageGeneration
{
    $service = app(ImageEditService::class);
    $generation = $service->request(test()->merchant, test()->product, test()->source, 7, [...$options, 'dispatch' => false]);
    $service->submit($generation);
    $poll && $service->poll($generation->fresh());

    return $generation->fresh();
}

describe('edit flow', function () {
    test('a full run reserves, submits, saves drafts, captures and records usage', function () {
        $generation = runEdit();

        expect($generation)->status->toBe('completed')->credits->toBe(20)
            ->and(GeneratedImage::sole())->status->toBe('draft')->mime->toBe('image/png')->generation_id->toBe($generation->id)->expires_at->not->toBeNull()
            ->and(($this->wallet)())->balance->toBe(80)->reserved->toBe(0)
            ->and(UsageLedger::where('feature', 'image_edit')->where('internal', false)->sum('credits_charged'))->toBe(20);
        Storage::disk('local')->assertExists(GeneratedImage::sole()->path);
    });

    test('the composed prompt holds the platform rules, instruction and analysis; the browser sends none of it', function () {
        runEdit(['instruction' => 'make it shiny']);

        $prompt = $this->fakeImage->submitted[0]->prompt;
        expect($prompt)->toContain('make it shiny')->toContain('A product photographed on a plain background.')->toContain(config('revo.moderation_platform_rules'))
            ->and($this->fakeImage->submitted[0]->imageUrl)->toBe('https://cdn.salla.sa/p/5.jpg');
    });

    test('an unchanged source is analyzed once and then served from the cache', function () {
        runEdit(['instruction' => 'one']);
        runEdit(['instruction' => 'two']);

        expect($this->fakeImage->analyses)->toHaveCount(1)->and(ImageAnalysis::count())->toBe(1);
    });

    test('variants cost the unit price each and produce that many drafts', function () {
        $generation = runEdit(['instruction' => 'x', 'variants' => 3]);

        expect($generation->credits)->toBe(60)->and(GeneratedImage::count())->toBe(3)->and(($this->wallet)())->balance->toBe(40);
    });

    test('a preset supplies the prompt, its own price and parameters', function () {
        $preset = Preset::factory()->create(['prompt' => 'Studio look', 'price_override' => 12, 'params' => []]);

        $generation = runEdit(['preset_id' => $preset->id]);

        expect($generation->credits)->toBe(12)->and($this->fakeImage->submitted[0]->prompt)->toContain('Studio look');
    });

    test('presets of other stores cannot be used', function () {
        $other = Merchant::factory()->active()->create();
        $preset = Preset::factory()->create(['merchant_id' => $other->merchant_id]);

        expect(fn () => $this->images->request($this->merchant, $this->product, $this->source, 1, ['preset_id' => $preset->id, 'dispatch' => false]))->toThrow(ReviewRejected::class);
    });

    test('invalid requests are refused before any charge', function (array $options) {
        expect(fn () => $this->images->request($this->merchant, $this->product, $this->source, 1, [...$options, 'dispatch' => false]))->toThrow(ReviewRejected::class)
            ->and(($this->wallet)())->reserved->toBe(0);
    })->with([
        'nothing chosen' => [[]],
        'too many variants' => [['instruction' => 'x', 'variants' => 4]],
        'zero variants' => [['instruction' => 'x', 'variants' => 0]],
    ]);

    test('only Salla CDN images can be edited', function () {
        $this->source->update(['url' => 'https://evil.example.com/a.jpg']);

        expect(fn () => $this->images->request($this->merchant, $this->product, $this->source, 1, ['instruction' => 'x', 'dispatch' => false]))->toThrow(InvalidArgumentException::class);
    });

    test('a plan without image editing is refused', function () {
        $this->merchant->update(['plan_status' => 'inactive']);

        expect(fn () => $this->images->request($this->merchant->fresh(), $this->product, $this->source, 1, ['instruction' => 'x', 'dispatch' => false]))->toThrow(PlanLocked::class);
    });

    test('not enough credit starts nothing', function () {
        app(WalletService::class)->deduct($this->merchant->merchant_id, 90);

        expect(fn () => $this->images->request($this->merchant, $this->product, $this->source, 1, ['instruction' => 'x', 'dispatch' => false]))->toThrow(InsufficientCredits::class)
            ->and(ImageGeneration::count())->toBe(0);
    });

    test('a blocked instruction is stopped before reserving or calling fal', function () {
        $this->fakeText->using(fn () => ['blocked' => true, 'category' => 'violence']);

        expect(fn () => $this->images->request($this->merchant, $this->product, $this->source, 1, ['instruction' => 'gore', 'dispatch' => false]))->toThrow(ModerationBlocked::class);
        expect($this->fakeImage->submitted)->toBeEmpty()->and(($this->wallet)())->reserved->toBe(0)->and(ModerationEvent::sole()->decision)->toBe('blocked');
    });

    test('a failed submit releases the credits', function () {
        $this->fakeImage->submitFails = new RuntimeException('down');
        $generation = $this->images->request($this->merchant, $this->product, $this->source, 1, ['instruction' => 'x', 'dispatch' => false]);

        $this->images->submit($generation);

        expect($generation->fresh())->status->toBe('failed')->and(($this->wallet)())->balance->toBe(100)->reserved->toBe(0);
    });

    test('a provider failure releases the credits and is logged', function () {
        $this->fakeImage->statuses(new ImageJobStatus(ImageJobStatus::FAILED, 'oops'));

        $generation = runEdit();

        expect($generation)->status->toBe('failed')->and(($this->wallet)())->balance->toBe(100)->and(GeneratedImage::count())->toBe(0)
            ->and(UsageLedger::where('status', 'error')->count())->toBeGreaterThan(0);
    });

    test('a job still running after five minutes is canceled and refunded', function () {
        $this->fakeImage->statuses(new ImageJobStatus(ImageJobStatus::RUNNING));
        $generation = runEdit(poll: false);

        expect($this->images->poll($generation->fresh()))->toBeTrue();
        $this->travel(5)->minutes();
        $this->travel(1)->seconds();
        expect($this->images->poll($generation->fresh()))->toBeFalse()
            ->and($generation->fresh())->status->toBe('timed_out')->and(($this->wallet)())->balance->toBe(100);
    });

    test('an output the moderation blocks is never stored and is not charged', function () {
        $this->fakeImage->moderationRule = fn () => 'nudity';

        $generation = runEdit();

        expect($generation)->status->toBe('blocked')->error->toContain('Nudity')->and(GeneratedImage::count())->toBe(0)->and(($this->wallet)())->balance->toBe(100);
    });

    test('moderation that cannot run fails closed', function () {
        $this->fakeImage->moderationFails = new RuntimeException('down');

        $generation = runEdit();

        expect($generation)->status->toBe('failed')->and(GeneratedImage::count())->toBe(0)->and(($this->wallet)())->balance->toBe(100);
    });

    test('the queue jobs drive the flow end to end', function () {
        $generation = $this->images->request($this->merchant, $this->product, $this->source, 1, ['instruction' => 'x', 'dispatch' => false]);
        Queue::fake([PollImageEdit::class]);

        SubmitImageEdit::dispatchSync($this->merchant->merchant_id, $generation->id);
        Queue::assertPushed(PollImageEdit::class);

        (new PollImageEdit($this->merchant->merchant_id, $generation->id))->handle($this->images, app(CurrentMerchant::class));
        expect($generation->fresh()->status)->toBe('completed');
    });

    test('quote shows the price before running', function () {
        expect($this->images->quote($this->merchant, null, 2))->toMatchArray(['credits' => 40, 'unit' => 20]);
    });
});

describe('upload hardening', function () {
    test('metadata chunks are stripped from PNG uploads', function () {
        expect(pngWithText())->toContain('secret-gps-data');

        $clean = app(ImageSanitizer::class)->clean(pngWithText());

        expect($clean['mime'])->toBe('image/png')->and($clean['contents'])->not->toContain('secret-gps-data')->and(strlen($clean['contents']))->toBeGreaterThan(50);
    });

    test('EXIF segments are stripped from JPEGs', function () {
        $exif = "\xFF\xE1".pack('n', 2 + 12)."Exif\0\0SECRETGPS";
        $jpeg = "\xFF\xD8\xFF\xE0".pack('n', 16)."JFIF\0\1\1\0\0\1\0\1\0\0".$exif."\xFF\xDB\x00\x03\x00\xFF\xDA\x00\x02\x00\x01\x02\x03\xFF\xD9";

        $clean = app(ImageSanitizer::class)->clean($jpeg);

        expect($clean['mime'])->toBe('image/jpeg')->and($clean['contents'])->not->toContain('SECRETGPS')->toContain('JFIF');
    });

    test('type is sniffed from the bytes, not the name', function () {
        expect(fn () => app(ImageSanitizer::class)->clean('<?php echo 1; ?>'))->toThrow(InvalidImage::class)
            ->and(fn () => app(ImageSanitizer::class)->clean('GIF89a'.str_repeat("\0", 30)))->toThrow(InvalidImage::class)
            ->and(fn () => app(ImageSanitizer::class)->clean(''))->toThrow(InvalidImage::class);
    });

    test('files over the size limit are refused', function () {
        config(['revo.limits.upload_megabytes' => 1]);

        expect(fn () => app(ImageSanitizer::class)->clean(png().str_repeat("\0", 1024 * 1024)))->toThrow(InvalidImage::class);
    });

    test('an upload lands in the library as approved with a sanitized name', function () {
        $image = app(MediaService::class)->upload($this->merchant, pngWithText(), '../../evil.php.png');

        expect($image)->status->toBe('approved')->source->toBe('upload')->name->toBe('evil.php.png');
        Storage::disk('local')->assertExists($image->path);
        expect(Storage::disk('local')->get($image->path))->not->toContain('secret-gps-data');
    });
});

describe('library', function () {
    beforeEach(function () {
        $this->media = app(MediaService::class);
        $this->draft = runEdit()->id ? GeneratedImage::sole() : null;
    });

    test('approving moves a draft into the library; other states cannot be approved', function () {
        $this->media->approve($this->draft);

        expect($this->draft->fresh())->status->toBe('approved')->approved_at->not->toBeNull()
            ->and(fn () => $this->media->approve($this->draft->fresh()))->toThrow(ReviewRejected::class);
    });

    test('discarding deletes the file', function () {
        $path = $this->draft->path;

        $this->media->discard($this->draft);

        Storage::disk('local')->assertMissing($path);
        expect($this->draft->fresh()->status)->toBe('discarded');
    });

    test('attaching uploads to Salla, links the product image and stops expiry', function () {
        $this->media->approve($this->draft);
        Http::fake(['api.salla.dev/admin/v2/products/777/images' => Http::response(['data' => ['id' => 901, 'url' => 'https://cdn.salla.sa/p/901.jpg', 'sort' => 3]])]);

        $record = $this->media->attach($this->merchant, $this->draft->fresh(), $this->product, 'ar', 'نص بديل');

        expect($record)->salla_image_id->toBe(901)->generated_image_id->toBe($this->draft->id)->alt->toBe('نص بديل')
            ->and($this->draft->fresh())->attached_product_id->toBe($this->product->id)->attached_salla_image_id->toBe(901)->expires_at->toBeNull();
        Http::assertSent(fn (Request $r) => $r->isMultipart() && $r->hasHeader('Accept-Language', 'ar'));
    });

    test('a draft cannot be attached before approval', function () {
        expect(fn () => $this->media->attach($this->merchant, $this->draft, $this->product, 'ar'))->toThrow(ReviewRejected::class);
    });

    test('deleting an attached image tombstones the product link so a re-sync cannot bring it back', function () {
        $this->media->approve($this->draft);
        Http::fake(['api.salla.dev/admin/v2/products/777/images' => Http::response(['data' => ['id' => 901, 'url' => 'https://cdn.salla.sa/p/901.jpg']])]);
        $this->media->attach($this->merchant, $this->draft->fresh(), $this->product, 'ar');

        $this->media->delete($this->draft->fresh());

        expect(GeneratedImage::count())->toBe(0)->and(ProductImage::where('salla_image_id', 901)->sole()->isTombstoned())->toBeTrue();
    });

    test('folders, tags and filters narrow the list', function () {
        $folder = MediaFolder::factory()->create(['merchant_id' => $this->merchant->merchant_id]);
        $this->media->approve($this->draft);
        $this->media->move($this->draft->fresh(), $folder);
        $this->media->tag($this->merchant, $this->draft->fresh(), ['summer', ' summer ', 'sale']);
        $upload = $this->media->upload($this->merchant, png(), 'logo.png');

        expect($this->media->library(['folder_id' => $folder->id])->pluck('id')->all())->toBe([$this->draft->id])
            ->and($this->media->library(['tag' => 'sale'])->pluck('id')->all())->toBe([$this->draft->id])
            ->and($this->media->library(['source' => 'upload'])->pluck('id')->all())->toBe([$upload->id])
            ->and($this->media->library(['search' => 'logo'])->pluck('id')->all())->toBe([$upload->id])
            ->and($this->media->library(['search' => '%'])->count())->toBe(0)
            ->and($this->media->library(['product_id' => $this->product->id])->pluck('id')->all())->toBe([$this->draft->id])
            ->and($this->draft->fresh()->tags()->count())->toBe(2);
    });

    test('the library of one store never shows another store\'s images', function () {
        $other = Merchant::factory()->active()->create();
        app(CurrentMerchant::class)->set($other->merchant_id);

        expect($this->media->library()->count())->toBe(0);
    });

    test('lineage: an edit of a generated image keeps its parent', function () {
        $this->media->approve($this->draft);
        $generation = $this->images->request($this->merchant, null, null, 1, ['instruction' => 'more', 'source_url' => 'https://v3.fal.media/x.png', 'parent_generated_image_id' => $this->draft->id, 'dispatch' => false]);
        $this->images->submit($generation);
        $this->images->poll($generation->fresh());

        expect(GeneratedImage::latest('id')->first()->parent_id)->toBe($this->draft->id);
    });
});

describe('retention and signed urls', function () {
    beforeEach(function () {
        $this->media = app(MediaService::class);
        $this->draft = GeneratedImage::find(runEdit()->id ? GeneratedImage::sole()->id : 0);
    });

    test('images expire after 60 days, attached ones never', function () {
        $keep = GeneratedImage::factory()->create(['merchant_id' => $this->merchant->merchant_id, 'path' => 'keep.png', 'attached_product_id' => $this->product->id, 'expires_at' => now()->subDay()]);
        $this->travel(61)->days();

        expect($this->media->expire())->toBe(1);
        expect($this->draft->fresh())->status->toBe('expired')->path->toBeNull()->and($keep->fresh()->status)->toBe('draft');
        Storage::disk('local')->assertMissing('x');
    });

    test('nothing expires early', function () {
        $this->travel(59)->days();

        expect($this->media->expire())->toBe(0);
    });

    test('the digest mails once a day for images about to expire', function () {
        Mail::fake();
        $this->travel(55)->days();

        $this->artisan('revo:media:expiry-digest')->assertSuccessful();
        $this->artisan('revo:media:expiry-digest')->assertSuccessful();

        Mail::assertSent(ImageExpiryDigest::class, 1);
        Mail::assertSent(ImageExpiryDigest::class, fn ($mail) => $mail->hasTo('shop@example.com') && $mail->count === 1);
    });

    test('no digest when nothing is close to expiring', function () {
        Mail::fake();

        $this->artisan('revo:media:expiry-digest')->assertSuccessful();

        Mail::assertNothingSent();
    });

    test('signed URLs serve the file for 15 minutes and not after, nor when tampered', function () {
        $url = app(ImageStore::class)->signedUrl($this->draft);

        $this->get($url)->assertOk()->assertHeader('Content-Type', 'image/png')->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->get($url.'x')->assertForbidden();
        $this->get(route('media.file', $this->draft->id))->assertForbidden();

        $this->travel(16)->minutes();
        $this->get($url)->assertForbidden();
    });

    test('the retention command runs every sweep and is safe to run twice', function () {
        $this->travel(61)->days();

        $this->artisan('revo:retention:run')->assertSuccessful();
        $this->artisan('revo:retention:run')->assertSuccessful();

        expect($this->draft->fresh()->status)->toBe('expired');
    });
});
