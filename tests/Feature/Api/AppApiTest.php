<?php

use App\Billing\WalletService;
use App\Enums\MerchantStatus;
use App\Images\MediaService;
use App\Models\AiModel;
use App\Models\ContentGeneration;
use App\Models\CreditPack;
use App\Models\CreditTransaction;
use App\Models\GeneratedImage;
use App\Models\Merchant;
use App\Models\MerchantToken;
use App\Models\Plan;
use App\Models\Preset;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductTranslation;
use App\Platform\EmbeddedSessionService;
use Database\Seeders\ContextOptionSeeder;
use Database\Seeders\ModerationCategorySeeder;
use Database\Seeders\PromptDefaultSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

function appHeaders(Merchant $merchant, ?int $user = 55): array
{
    return ['Authorization' => 'Bearer '.app(EmbeddedSessionService::class)->start($merchant->merchant_id, $user)['token']];
}

beforeEach(function () {
    Storage::fake('local');
    $this->seed([PromptDefaultSeeder::class, ModerationCategorySeeder::class, ContextOptionSeeder::class]);
    Plan::factory()->create(['slug' => 'plus', 'feature_flags' => ['product_content' => true, 'image_edit' => true]]);
    AiModel::factory()->defaultFor('text_moderation')->create(['features' => ['text_moderation']]);
    AiModel::factory()->defaultFor('product_content')->create(['features' => ['product_content', 'field_regeneration']]);
    AiModel::factory()->image()->defaultFor('image_edit')->create(['features' => ['image_edit']]);
    AiModel::factory()->image()->defaultFor('image_moderation')->create(['features' => ['image_moderation']]);
    $this->fakeText->using(fn ($request) => isset($request->tool['schema']['properties']['blocked']) ? ['blocked' => false, 'category' => null] : ['name' => 'اسم جديد']);
    $this->merchant = Merchant::factory()->active()->create(['plan_code' => 'plus', 'plan_status' => 'active', 'default_language' => 'ar', 'enabled_languages' => ['ar', 'en']]);
    $this->other = Merchant::factory()->active()->create(['plan_code' => 'plus', 'plan_status' => 'active', 'default_language' => 'ar']);
    MerchantToken::factory()->create(['merchant_id' => $this->merchant->merchant_id]);
    app(WalletService::class)->credit($this->merchant->merchant_id, 100, CreditTransaction::MANUAL_GRANT);
    $this->product = Product::factory()->create(['merchant_id' => $this->merchant->merchant_id, 'salla_product_id' => 777]);
    ProductTranslation::factory()->create(['product_id' => $this->product->id, 'merchant_id' => $this->merchant->merchant_id, 'lang' => 'ar', 'name' => 'قميص']);
    $this->theirs = Product::factory()->create(['merchant_id' => $this->other->merchant_id]);
    ProductTranslation::factory()->create(['product_id' => $this->theirs->id, 'merchant_id' => $this->other->merchant_id, 'lang' => 'ar', 'name' => 'THEIRS']);
    $this->h = appHeaders($this->merchant);
});

describe('authentication', function () {
    test('every app endpoint refuses requests without a valid session', function (string $method, string $uri) {
        $this->json($method, $uri)->assertUnauthorized();
        $this->withHeaders(['Authorization' => 'Bearer nope'])->json($method, $uri)->assertUnauthorized();
    })->with([
        ['GET', '/api/app/overview'], ['GET', '/api/app/products'], ['POST', '/api/app/products/1/generate'], ['GET', '/api/app/media'],
        ['GET', '/api/app/settings'], ['GET', '/api/app/billing/packs'], ['POST', '/api/app/bulk'], ['PUT', '/api/app/settings/context'],
    ]);

    test('an uninstalled store loses access immediately', function () {
        $this->merchant->update(['status' => MerchantStatus::Uninstalled]);

        $this->withHeaders($this->h)->getJson('/api/app/overview')->assertUnauthorized();
    });
});

describe('overview', function () {
    test('numbers match the database', function () {
        ContentGeneration::factory()->count(2)->create(['product_id' => $this->product->id, 'merchant_id' => $this->merchant->merchant_id, 'status' => 'draft']);
        GeneratedImage::factory()->create(['merchant_id' => $this->merchant->merchant_id, 'status' => 'approved']);

        $data = $this->withHeaders($this->h)->getJson('/api/app/overview')->assertOk()->json('data');

        expect($data['credits'])->toMatchArray(['balance' => 100, 'available' => 100, 'reserved' => 0])
            ->and($data['products'])->toMatchArray(['total' => 1, 'missing_seo' => 1])
            ->and($data['content']['drafts_waiting'])->toBe(2)->and($data['images']['library'])->toBe(1)
            ->and($data['plan']['features']['image_edit'])->toBeTrue();
    });

    test('low balance is flagged at the threshold', function () {
        app(WalletService::class)->deduct($this->merchant->merchant_id, 60);

        expect($this->withHeaders($this->h)->getJson('/api/app/overview')->json('data.credits.low'))->toBeTrue();
    });
});

describe('products', function () {
    test('lists only this store\'s products, with search and filters', function () {
        $res = $this->withHeaders($this->h)->getJson('/api/app/products')->assertOk();
        expect(collect($res->json('data'))->pluck('name')->all())->toBe(['قميص']);

        expect($this->withHeaders($this->h)->getJson('/api/app/products?search=THEIRS')->json('data'))->toBe([])
            ->and($this->withHeaders($this->h)->getJson('/api/app/products?search=%25')->json('data'))->toBe([])
            ->and($this->withHeaders($this->h)->getJson('/api/app/products?search='.urlencode('قمي'))->json('data'))->toHaveCount(1)
            ->and($this->withHeaders($this->h)->getJson('/api/app/products?filter=missing_seo')->json('data'))->toHaveCount(1);
    });

    test('another store\'s product is a 404 for every product route', function () {
        $id = $this->theirs->id;
        $h = $this->withHeaders($this->h);

        $h->getJson("/api/app/products/{$id}")->assertNotFound();
        $h->postJson("/api/app/products/{$id}/generate", ['lang' => 'ar', 'fields' => ['name']])->assertNotFound();
        $h->postJson("/api/app/products/{$id}/resync")->assertNotFound();
        $h->getJson("/api/app/products/{$id}/versions")->assertNotFound();
    });

    test('generate queues work, reserves credits and validates input', function () {
        Queue::fake();

        $res = $this->withHeaders($this->h)->postJson("/api/app/products/{$this->product->id}/generate", ['lang' => 'ar', 'fields' => ['name']])->assertStatus(202);

        expect($res->json('data.status'))->toBe('queued')->and($res->json('data.credits'))->toBe(8);
        $this->withHeaders($this->h)->postJson("/api/app/products/{$this->product->id}/generate", ['lang' => 'fr', 'fields' => ['name']])->assertStatus(422);
        $this->withHeaders($this->h)->postJson("/api/app/products/{$this->product->id}/generate", [])->assertStatus(422);
    });

    test('not enough credits returns 402 and a locked plan 403', function () {
        Queue::fake();
        app(WalletService::class)->deduct($this->merchant->merchant_id, 95);
        $this->withHeaders($this->h)->postJson("/api/app/products/{$this->product->id}/generate", ['lang' => 'ar', 'fields' => ['name']])->assertStatus(402)->assertJsonPath('required', 8);

        $this->merchant->update(['plan_status' => 'inactive']);
        $this->withHeaders($this->h)->postJson("/api/app/products/{$this->product->id}/generate", ['lang' => 'ar', 'fields' => ['name']])->assertForbidden();
    });

    test('generate, review and approve work end to end over the API', function () {
        Http::fake(['api.salla.dev/admin/v2/products/777' => Http::response(['data' => []])]);

        $id = $this->withHeaders($this->h)->postJson("/api/app/products/{$this->product->id}/generate", ['lang' => 'ar', 'fields' => ['name']])->json('data.id');
        $this->withHeaders($this->h)->getJson("/api/app/generations/{$id}")->assertOk()->assertJsonPath('data.status', 'draft')->assertJsonPath('data.output.name', 'اسم جديد');

        $this->withHeaders($this->h)->postJson("/api/app/generations/{$id}/approve", ['edits' => ['name' => str_repeat('x', 101)]])->assertStatus(422)->assertJsonPath('errors.name.0', 'Over the 100 character limit.');
        $this->withHeaders($this->h)->postJson("/api/app/generations/{$id}/approve")->assertOk()->assertJsonPath('data.status', 'approved');
        $this->withHeaders($this->h)->getJson("/api/app/products/{$this->product->id}/versions")->assertOk()->assertJsonCount(2, 'data');
    });

    test('another store\'s drafts cannot be read, approved or rejected', function () {
        $draft = ContentGeneration::factory()->create(['product_id' => $this->theirs->id, 'merchant_id' => $this->other->merchant_id]);

        $this->withHeaders($this->h)->getJson("/api/app/generations/{$draft->id}")->assertNotFound();
        $this->withHeaders($this->h)->postJson("/api/app/generations/{$draft->id}/approve")->assertNotFound();
        $this->withHeaders($this->h)->postJson("/api/app/generations/{$draft->id}/reject")->assertNotFound();
        expect($this->withHeaders($this->h)->getJson('/api/app/generations')->json('data'))->toBe([]);
    });

    test('an outdated draft answers 409 until acknowledged', function () {
        Http::fake(['api.salla.dev/admin/v2/products/777' => Http::response(['data' => []])]);
        $this->product->update(['relevant_hash' => 'a']);
        $id = $this->withHeaders($this->h)->postJson("/api/app/products/{$this->product->id}/generate", ['lang' => 'ar', 'fields' => ['name']])->json('data.id');
        $this->product->update(['relevant_hash' => 'b']);

        $this->withHeaders($this->h)->postJson("/api/app/generations/{$id}/approve")->assertStatus(409);
        $this->withHeaders($this->h)->postJson("/api/app/generations/{$id}/approve", ['acknowledge_outdated' => true])->assertOk();
    });

    test('bulk estimate and start over the API', function () {
        Product::factory()->count(2)->create(['merchant_id' => $this->merchant->merchant_id]);

        $est = $this->withHeaders($this->h)->postJson('/api/app/bulk/estimate', ['filter' => ['type' => 'all'], 'languages' => ['ar']])->assertOk()->json('data');
        expect($est)->toMatchArray(['products' => 3, 'estimate' => 24]);

        $job = $this->withHeaders($this->h)->postJson('/api/app/bulk', ['filter' => ['type' => 'all'], 'fields' => ['name'], 'languages' => ['ar']])->assertStatus(202)->json('data.id');
        $this->withHeaders($this->h)->getJson("/api/app/bulk/{$job}")->assertOk()->assertJsonPath('data.status', 'completed');
        $this->withHeaders(appHeaders($this->other))->getJson("/api/app/bulk/{$job}")->assertNotFound();
    });
});

describe('settings', function () {
    test('the snapshot can be read and every group saved', function () {
        $h = $this->withHeaders($this->h);

        $h->putJson('/api/app/settings/general', ['auto_publish' => true, 'description_length' => 'long'])->assertOk()->assertJsonPath('data.general.auto_publish', true);
        $h->putJson('/api/app/settings/prompts/product_tone', ['body' => 'Custom tone'])->assertOk()->assertJsonPath('data.prompts.product_tone.effective', 'Custom tone');
        $h->putJson('/api/app/settings/prompts/product_tone', ['body' => null])->assertOk()->assertJsonPath('data.prompts.product_tone.override', null);
        $h->putJson('/api/app/settings/context', ['tagline' => 'Hello'])->assertOk()->assertJsonPath('data.store_context.tagline', 'Hello');
    });

    test('validation and moderation errors come back as 422 with the category only', function () {
        $this->withHeaders($this->h)->putJson('/api/app/settings/general', ['description_length' => 'huge'])->assertStatus(422);

        $this->fakeText->using(fn () => ['blocked' => true, 'category' => 'violence']);
        $this->withHeaders($this->h)->putJson('/api/app/settings/context', ['tagline' => 'gore'])->assertStatus(422)->assertJsonPath('message', 'Blocked: Violence and gore');
    });

    test('presets are managed per store and cross-store ids are 404', function () {
        $res = $this->withHeaders($this->h)->postJson('/api/app/settings/presets', ['name_ar' => 'ق', 'name_en' => 'Mine', 'prompt' => 'p'])->assertCreated();
        $id = $res->json('data.id');
        $theirs = Preset::factory()->create(['merchant_id' => $this->other->merchant_id]);

        $this->withHeaders($this->h)->putJson("/api/app/settings/presets/{$id}", ['name_ar' => 'ق', 'name_en' => 'Renamed', 'prompt' => 'p'])->assertOk()->assertJsonPath('data.name_en', 'Renamed');
        $this->withHeaders($this->h)->putJson("/api/app/settings/presets/{$theirs->id}", ['name_ar' => 'ق', 'name_en' => 'x', 'prompt' => 'p'])->assertNotFound();
        $this->withHeaders($this->h)->deleteJson("/api/app/settings/presets/{$theirs->id}")->assertNotFound();
        $this->withHeaders($this->h)->deleteJson("/api/app/settings/presets/{$id}")->assertNoContent();
    });

    test('drafts autosave per user and per form', function () {
        $h = $this->withHeaders($this->h);
        $h->putJson('/api/app/drafts/settings.prompts', ['payload' => ['a' => 1]])->assertOk();
        $h->getJson('/api/app/drafts/settings.prompts')->assertJsonPath('data.a', 1);
        $this->withHeaders(appHeaders($this->merchant, 99))->getJson('/api/app/drafts/settings.prompts')->assertJsonPath('data', null);
        $h->deleteJson('/api/app/drafts/settings.prompts')->assertNoContent();
        $h->getJson('/api/app/drafts/settings.prompts')->assertJsonPath('data', null);
    });
});

describe('images and media', function () {
    beforeEach(function () {
        $this->image = ProductImage::factory()->create(['product_id' => $this->product->id, 'merchant_id' => $this->merchant->merchant_id, 'salla_image_id' => 5, 'url' => 'https://cdn.salla.sa/p/5.jpg']);
        Http::fake(['v3.fal.media/*' => Http::response(base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='), 200)]);
    });

    test('an edit runs through the queue and the result has signed URLs', function () {
        $res = $this->withHeaders($this->h)->postJson("/api/app/products/{$this->product->id}/images/{$this->image->id}/edit", ['instruction' => 'white bg'])->assertStatus(202);
        $id = $res->json('data.id');

        $show = $this->withHeaders($this->h)->getJson("/api/app/image-generations/{$id}")->assertOk();
        expect($show->json('data.status'))->toBe('completed')->and($show->json('data.images.0.url'))->toContain('signature=');
        $this->get($show->json('data.images.0.url'))->assertOk();
    });

    test('an image of another product or store cannot be edited', function () {
        $theirImage = ProductImage::factory()->create(['product_id' => $this->theirs->id, 'merchant_id' => $this->other->merchant_id]);

        $this->withHeaders($this->h)->postJson("/api/app/products/{$this->product->id}/images/{$theirImage->id}/edit", ['instruction' => 'x'])->assertNotFound();
        $this->withHeaders($this->h)->postJson("/api/app/products/{$this->theirs->id}/images/{$theirImage->id}/edit", ['instruction' => 'x'])->assertNotFound();
    });

    test('quote returns the price', function () {
        $this->withHeaders($this->h)->getJson('/api/app/images/quote?variants=2')->assertOk()->assertJsonPath('data.credits', 40);
    });

    test('upload, list, tag, move, approve flow and cross-store isolation', function () {
        $h = $this->withHeaders($this->h);
        $png = UploadedFile::fake()->createWithContent('logo.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='));

        $id = $h->post('/api/app/media', ['file' => $png], ['Accept' => 'application/json'])->assertCreated()->json('data.id');
        $folder = $h->postJson('/api/app/media/folders', ['name' => 'Logos'])->assertCreated()->json('data.id');
        $h->putJson("/api/app/media/{$id}/folder", ['folder_id' => $folder])->assertOk();
        $h->putJson("/api/app/media/{$id}/tags", ['tags' => ['brand']])->assertOk()->assertJsonPath('data.tags.0', 'brand');
        expect($h->getJson("/api/app/media?folder_id={$folder}&tag=brand")->json('data'))->toHaveCount(1);

        $h->postJson('/api/app/media/folders', ['name' => 'Logos'])->assertStatus(422);
        $other = $this->withHeaders(appHeaders($this->other));
        expect($other->getJson('/api/app/media')->json('data'))->toBe([]);
        $other->deleteJson("/api/app/media/{$id}")->assertNotFound();
        $other->putJson("/api/app/media/{$id}/tags", ['tags' => ['x']])->assertNotFound();
        $other->postJson("/api/app/media/{$id}/attach", ['product_id' => $this->theirs->id, 'lang' => 'ar'])->assertNotFound();
    });

    test('non-image uploads are rejected', function () {
        $this->withHeaders($this->h)->post('/api/app/media', ['file' => UploadedFile::fake()->createWithContent('a.png', '<?php evil();')], ['Accept' => 'application/json'])->assertStatus(422);
    });

    test('attach sends the image to Salla', function () {
        $img = app(MediaService::class)->upload($this->merchant, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='), 'a.png');
        Http::fake(['api.salla.dev/admin/v2/products/777/images' => Http::response(['data' => ['id' => 900, 'url' => 'https://cdn.salla.sa/p/900.jpg']])]);

        $this->withHeaders($this->h)->postJson("/api/app/media/{$img->id}/attach", ['product_id' => $this->product->id, 'lang' => 'ar', 'alt' => 'نص'])->assertOk()->assertJsonPath('data.salla_image_id', 900);
    });
});

describe('billing', function () {
    test('packs, intent and result add credits once per order', function () {
        $pack = CreditPack::factory()->create(['credits' => 500, 'active' => true]);
        CreditPack::factory()->create(['active' => false]);
        $h = $this->withHeaders($this->h);

        expect($h->getJson('/api/app/billing/packs')->json('data'))->toHaveCount(1);
        $uuid = $h->postJson('/api/app/billing/intents', ['pack_id' => $pack->id])->assertCreated()->json('data.uuid');

        $h->postJson("/api/app/billing/intents/{$uuid}/result", ['status' => 'success', 'order_id' => 'o-1'])->assertOk()->assertJsonPath('data.status', 'confirmed');
        $h->postJson("/api/app/billing/intents/{$uuid}/result", ['status' => 'success', 'order_id' => 'o-1']);
        expect(app(WalletService::class)->walletFor($this->merchant->merchant_id)->balance)->toBe(600);

        $h->getJson('/api/app/billing/history')->assertOk()->assertJsonPath('data.0.type', 'purchase');
    });

    test('inactive packs cannot be bought and intents of other stores are 404', function () {
        $inactive = CreditPack::factory()->create(['active' => false]);
        $pack = CreditPack::factory()->create(['active' => true]);
        $uuid = $this->withHeaders(appHeaders($this->other))->postJson('/api/app/billing/intents', ['pack_id' => $pack->id])->json('data.uuid');

        $this->withHeaders($this->h)->postJson('/api/app/billing/intents', ['pack_id' => $inactive->id])->assertStatus(422);
        $this->withHeaders($this->h)->postJson("/api/app/billing/intents/{$uuid}/result", ['status' => 'success', 'order_id' => 'o-2'])->assertNotFound();
        expect(app(WalletService::class)->walletFor($this->merchant->merchant_id)->balance)->toBe(100);
    });
});
