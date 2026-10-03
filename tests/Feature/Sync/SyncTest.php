<?php

use App\Enums\MerchantStatus;
use App\Models\AppEvent;
use App\Models\CreditTransaction;
use App\Models\Merchant;
use App\Models\MerchantToken;
use App\Models\Product;
use App\Models\ProductContext;
use App\Models\ProductImage;
use App\Models\ProductTranslation;
use App\Support\CurrentMerchant;
use App\Sync\FetchProductTranslations;
use App\Sync\InitialProductSync;
use App\Sync\MissingToken;
use App\Sync\ProductSyncService;
use App\Sync\ResyncProduct;
use App\Sync\SallaApi;
use App\Sync\SyncProductPage;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;

beforeEach(function () {
    config(['salla.webhook_secret' => SALLA_TEST_SECRET]);
    $this->travelTo('2026-06-15 12:00:00');
    Sleep::fake();
    Http::preventStrayRequests();
    $this->merchant = Merchant::factory()->create([
        'merchant_id' => SALLA_TEST_MERCHANT, 'status' => MerchantStatus::Syncing,
        'default_language' => 'ar', 'enabled_languages' => ['ar'],
    ]);
    MerchantToken::factory()->create(['merchant_id' => SALLA_TEST_MERCHANT, 'access_token' => 'tok-1']);
});

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function sallaProduct(int $id, array $overrides = []): array
{
    return array_merge([
        'id' => $id, 'sku' => "SKU-{$id}", 'type' => 'product', 'status' => 'sale', 'name' => "منتج {$id}",
        'description' => "<p>وصف {$id}</p>", 'price' => ['amount' => 100.5, 'currency' => 'SAR'], 'quantity' => 5,
        'promotion' => ['title' => 'جديد', 'sub_title' => 'شتاء'],
        'metadata' => ['title' => 'عنوان', 'description' => 'وصف', 'url' => 'slug'],
        'images' => [
            ['id' => $id * 10 + 1, 'type' => 'image', 'url' => "https://cdn.salla.sa/{$id}-1.jpg", 'alt' => 'one', 'main' => true, 'sort' => 1],
            ['id' => $id * 10 + 2, 'type' => 'image', 'url' => "https://cdn.salla.sa/{$id}-2.jpg", 'alt' => 'two', 'main' => false, 'sort' => 2],
        ],
    ], $overrides);
}

function fakeCatalog(int $total, int $perPage = 60, ?Closure $each = null): void
{
    Http::fake(['api.salla.dev/admin/v2/products?per_page=*' => function (Request $request) use ($total, $perPage, $each) {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
        $page = (int) ($query['page'] ?? 1);
        $ids = range(($page - 1) * $perPage + 1, min($page * $perPage, $total));
        $each && $each($page);

        return Http::response([
            'data' => array_map(fn (int $id) => sallaProduct($id), $ids),
            'pagination' => ['count' => count($ids), 'total' => $total, 'perPage' => $perPage, 'currentPage' => $page, 'totalPages' => (int) ceil($total / $perPage)],
        ]);
    }]);
}

describe('initial sync', function () {
    test('a store with 500 products ends with exactly 500 rows and no duplicates, one page at a time', function () {
        $this->merchant->update(['enabled_languages' => ['ar']]);
        fakeCatalog(500);
        $pages = [];

        InitialProductSync::dispatchSync(SALLA_TEST_MERCHANT);

        Http::assertSent(function (Request $request) use (&$pages) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
            $pages[] = (int) $query['page'];

            return $request['per_page'] === '60' || ($query['per_page'] ?? null) === '60';
        });
        expect(Product::count())->toBe(500)
            ->and(Product::distinct('salla_product_id')->count('salla_product_id'))->toBe(500)
            ->and($pages)->toBe(range(1, 9))
            ->and($this->merchant->fresh())->status->toBe(MerchantStatus::Active)->sync_pages_done->toBe(9)->sync_total_pages->toBe(9)->sync_finished_at->not->toBeNull();
    });

    test('a forced restart halfway still ends with 500 products', function () {
        $this->merchant->update(['enabled_languages' => ['ar']]);
        fakeCatalog(500);
        $syncService = app(ProductSyncService::class);
        $started = now();

        // First run dies after page 4; a new run starts again from page 1.
        foreach (range(1, 4) as $page) {
            $syncService->syncPage($this->merchant, $page, $started, false);
        }
        expect(Product::count())->toBe(240);

        InitialProductSync::dispatchSync(SALLA_TEST_MERCHANT);

        expect(Product::count())->toBe(500)->and(Product::withTrashed()->count())->toBe(500);
    });

    test('a run older than the 15 minute pagination window restarts from page 1', function () {
        fakeCatalog(500);
        Queue::fake([SyncProductPage::class]);
        $old = now()->subMinutes(16)->toIso8601String();

        (new SyncProductPage(SALLA_TEST_MERCHANT, 5, $old))->handle(app(ProductSyncService::class), app(CurrentMerchant::class));

        Queue::assertPushed(SyncProductPage::class, fn ($job) => $job->page === 1 && $job->runStartedAt !== $old);
        Http::assertNothingSent();
    });

    test('a run inside the window continues with the next page', function () {
        fakeCatalog(500);
        Queue::fake([SyncProductPage::class]);

        (new SyncProductPage(SALLA_TEST_MERCHANT, 2, now()->subMinutes(10)->toIso8601String()))->handle(app(ProductSyncService::class), app(CurrentMerchant::class));

        Queue::assertPushed(SyncProductPage::class, fn ($job) => $job->page === 3);
    });

    test('progress is visible while syncing', function () {
        fakeCatalog(500);
        $started = now();

        app(ProductSyncService::class)->syncPage($this->merchant, 3, $started, false);

        expect($this->merchant->fresh())->sync_pages_done->toBe(3)->sync_total_pages->toBe(9)->status->toBe(MerchantStatus::Syncing);
    });

    test('an uninstalled store stops syncing', function () {
        fakeCatalog(120);
        $this->merchant->update(['status' => MerchantStatus::Uninstalled]);

        SyncProductPage::dispatchSync(SALLA_TEST_MERCHANT, 1, now()->toIso8601String());

        expect(Product::count())->toBe(0);
        Http::assertNothingSent();
    });

    test('sync resets progress counters when it starts', function () {
        fakeCatalog(10);
        $this->merchant->update(['sync_pages_done' => 7, 'sync_total_pages' => 9]);
        Queue::fake([SyncProductPage::class]);

        InitialProductSync::dispatchSync(SALLA_TEST_MERCHANT);

        expect($this->merchant->fresh())->sync_pages_done->toBe(0)->sync_total_pages->toBeNull();
    });
});

describe('product storage', function () {
    test('core fields and the raw payload are stored with default-language text', function () {
        $payload = sallaProduct(7, ['sku' => 'ABC', 'mpn' => 'M1', 'gtin' => 'G1', 'brand' => ['id' => 1, 'name' => 'Brand'], 'categories' => [['id' => 5]], 'tags' => ['a'], 'options' => [['id' => 1]], 'skus' => [['id' => 2]], 'urls' => ['customer' => 'https://shop.test/p7']]);

        $product = app(ProductSyncService::class)->upsert($this->merchant, $payload);

        $product->refresh();
        expect($product)->sku->toBe('ABC')->mpn->toBe('M1')->gtin->toBe('G1')->type->toBe('product')->status->toBe('sale')
            ->currency->toBe('SAR')->quantity->toBe(5)->is_available->toBeTrue()->raw->toBe($payload)
            ->and((float) $product->price)->toBe(100.5)
            ->and($product->brand)->toBe(['id' => 1, 'name' => 'Brand'])->and($product->urls)->toBe(['customer' => 'https://shop.test/p7'])
            ->and(ProductTranslation::sole())->lang->toBe('ar')->name->toBe('منتج 7')->description->toBe('<p>وصف 7</p>')
            ->promotion_title->toBe('جديد')->subtitle->toBe('شتاء')->metadata_title->toBe('عنوان')->metadata_description->toBe('وصف')->metadata_url->toBe('slug');
    });

    test('only entries of type image become image records', function () {
        $payload = sallaProduct(7, ['images' => [
            ['id' => 71, 'type' => 'image', 'url' => 'https://cdn.salla.sa/a.jpg', 'main' => true, 'sort' => 1, 'three_d_image_url' => 'https://cdn.salla.sa/3d'],
            ['id' => 72, 'type' => 'image', 'url' => 'https://cdn.salla.sa/b.jpg', 'sort' => 2],
            ['id' => 73, 'type' => 'video', 'url' => 'https://cdn.salla.sa/v.mp4', 'sort' => 3],
        ]]);

        app(ProductSyncService::class)->upsert($this->merchant, $payload);

        expect(ProductImage::orderBy('sort')->pluck('salla_image_id')->all())->toBe([71, 72])
            ->and(ProductImage::where('salla_image_id', 71)->sole())->main->toBeTrue()->three_d_image_url->toBe('https://cdn.salla.sa/3d');
    });

    test('syncing the same product again updates it in place', function () {
        $service = app(ProductSyncService::class);
        $service->upsert($this->merchant, sallaProduct(7));

        $service->upsert($this->merchant, sallaProduct(7, ['name' => 'جديد', 'price' => ['amount' => 20, 'currency' => 'SAR']]));

        expect(Product::count())->toBe(1)->and((float) Product::sole()->price)->toBe(20.0)
            ->and(ProductTranslation::sole()->name)->toBe('جديد')->and(ProductImage::count())->toBe(2);
    });

    test('products of two stores with the same Salla id stay separate', function () {
        $other = Merchant::factory()->create(['default_language' => 'ar']);

        app(ProductSyncService::class)->upsert($this->merchant, sallaProduct(7));
        app(ProductSyncService::class)->upsert($other, sallaProduct(7));

        expect(Product::withoutGlobalScopes()->where('salla_product_id', 7)->count())->toBe(2);
    });

    test('a non-default language payload fills only that language row', function () {
        $service = app(ProductSyncService::class);
        $product = $service->upsert($this->merchant, sallaProduct(7));

        $service->upsert($this->merchant, sallaProduct(7, ['name' => 'Evening dress', 'description' => '<p>Nice</p>', 'price' => ['amount' => 1]]), 'en');

        expect(ProductTranslation::where('lang', 'en')->sole())->name->toBe('Evening dress')
            ->and(ProductTranslation::where('lang', 'ar')->sole()->name)->toBe('منتج 7')
            ->and((float) $product->fresh()->price)->toBe(100.5);
    });
});

describe('languages', function () {
    test('an Arabic-default store with English enabled gets both language rows for every product', function () {
        $this->merchant->update(['enabled_languages' => ['ar', 'en']]);
        fakeCatalog(3);
        Http::fake([
            'api.salla.dev/admin/v2/products/*' => function (Request $request) {
                $id = (int) basename(parse_url($request->url(), PHP_URL_PATH));

                return Http::response(['data' => sallaProduct($id, ['name' => "Product {$id}", 'description' => "<p>Desc {$id}</p>"])]);
            },
        ]);

        InitialProductSync::dispatchSync(SALLA_TEST_MERCHANT);

        expect(Product::count())->toBe(3)->and(ProductTranslation::where('lang', 'ar')->count())->toBe(3)->and(ProductTranslation::where('lang', 'en')->count())->toBe(3)
            ->and(ProductTranslation::where('lang', 'en')->where('product_id', Product::orderBy('id')->first()->id)->sole()->name)->toBe('Product 1');
        Http::assertSent(fn (Request $request) => str_contains($request->url(), '/products/1') && $request->hasHeader('Accept-Language', 'en'));
    });

    test('a store with only its default language fetches nothing extra', function () {
        $this->merchant->update(['enabled_languages' => ['ar']]);
        fakeCatalog(2);

        InitialProductSync::dispatchSync(SALLA_TEST_MERCHANT);

        expect(ProductTranslation::where('lang', 'en')->count())->toBe(0);
        Http::assertSentCount(1);
    });

    test('five rapid updates cause one round of language fetches', function () {
        $this->merchant->update(['enabled_languages' => ['ar', 'en']]);
        Queue::fake([FetchProductTranslations::class]);
        $service = app(ProductSyncService::class);
        $product = $service->upsert($this->merchant, sallaProduct(7));

        foreach (range(1, 5) as $i) {
            $service->queueTranslations($this->merchant, $product);
        }

        Queue::assertPushed(FetchProductTranslations::class, 1);
    });

    test('the debounce window is 60 seconds', function () {
        $this->merchant->update(['enabled_languages' => ['ar', 'en']]);
        Queue::fake([FetchProductTranslations::class]);
        $service = app(ProductSyncService::class);
        $product = $service->upsert($this->merchant, sallaProduct(7));
        $service->queueTranslations($this->merchant, $product);

        $this->travel(59)->seconds();
        $service->queueTranslations($this->merchant, $product);
        $this->travel(2)->seconds();
        Cache::flush();
        $service->queueTranslations($this->merchant, $product);

        Queue::assertPushed(FetchProductTranslations::class, 2);
    });
});

describe('webhooks', function () {
    test('product created upserts the product, its text and images', function () {
        $this->merchant->update(['enabled_languages' => ['ar', 'en']]);
        Queue::fake([FetchProductTranslations::class]);

        deliverSalla(sallaEvent('product.created', sallaProduct(7), 0))->assertOk();

        expect(Product::sole())->salla_product_id->toBe(7)
            ->and(ProductImage::count())->toBe(2)->and(ProductTranslation::count())->toBe(1);
        Queue::assertPushed(FetchProductTranslations::class);
    });

    test('changing a price in Salla updates Revo and marks the context stale', function () {
        Queue::fake([FetchProductTranslations::class]);
        deliverSalla(sallaEvent('product.created', sallaProduct(7), 0));
        ProductContext::query()->update(['stale' => false]);

        deliverSalla(sallaEvent('product.updated', sallaProduct(7, ['price' => ['amount' => 55, 'currency' => 'SAR']]), 1));

        expect((float) Product::sole()->price)->toBe(55.0)->and(ProductContext::sole()->stale)->toBeTrue();
    });

    test('an update that changes nothing relevant leaves the context fresh', function () {
        Queue::fake([FetchProductTranslations::class]);
        deliverSalla(sallaEvent('product.created', sallaProduct(7), 0));
        ProductContext::query()->update(['stale' => false]);

        deliverSalla(sallaEvent('product.updated', sallaProduct(7), 1));

        expect(ProductContext::sole()->stale)->toBeFalse();
    });

    test('an older update arriving after a newer one changes nothing', function () {
        Queue::fake([FetchProductTranslations::class]);
        deliverSalla(sallaEvent('product.updated', sallaProduct(7, ['name' => 'Newest']), 10));

        deliverSalla(sallaEvent('product.updated', sallaProduct(7, ['name' => 'Older']), 5));

        expect(ProductTranslation::sole()->name)->toBe('Newest')
            ->and(AppEvent::where('event', 'product.updated')->orderBy('id')->get()->last()->status->value)->toBe('ignored');
    });

    test('product deleted soft-deletes and hides the product', function () {
        Queue::fake([FetchProductTranslations::class]);
        deliverSalla(sallaEvent('product.created', sallaProduct(7), 0));

        deliverSalla(sallaEvent('product.deleted', ['id' => 7], 1))->assertOk();

        expect(Product::count())->toBe(0)->and(Product::withTrashed()->count())->toBe(1);
    });

    test('a delete for an unknown product is ignored and a stale delete cannot remove a newer product', function () {
        Queue::fake([FetchProductTranslations::class]);
        deliverSalla(sallaEvent('product.deleted', ['id' => 99], 0));
        deliverSalla(sallaEvent('product.updated', sallaProduct(7), 10));

        deliverSalla(sallaEvent('product.deleted', ['id' => 7], 5));

        expect(Product::count())->toBe(1);
    });

    test('a product recreated after deletion comes back with the same row', function () {
        Queue::fake([FetchProductTranslations::class]);
        deliverSalla(sallaEvent('product.created', sallaProduct(7), 0));
        $id = Product::sole()->id;
        deliverSalla(sallaEvent('product.deleted', ['id' => 7], 1));

        deliverSalla(sallaEvent('product.updated', sallaProduct(7), 2));

        expect(Product::sole()->id)->toBe($id);
    });

    test('product events are ignored once the app is uninstalled', function () {
        $this->merchant->update(['status' => MerchantStatus::Uninstalled]);

        deliverSalla(sallaEvent('product.updated', sallaProduct(7), 0))->assertOk();

        expect(Product::count())->toBe(0);
    });

    test('the same delivery twice changes data once', function () {
        Queue::fake([FetchProductTranslations::class]);
        $body = sallaEvent('product.created', sallaProduct(7), 0);

        deliverSalla($body);
        deliverSalla($body);

        expect(Product::count())->toBe(1)->and(AppEvent::count())->toBe(1);
    });
});

describe('image events and tombstones', function () {
    function imagePayload(array $ids): array
    {
        return ['product_id' => 7, 'images' => array_map(fn (int $id) => ['id' => $id, 'type' => 'image', 'url' => "https://cdn.salla.sa/{$id}.jpg", 'sort' => $id], $ids)];
    }

    test('the owner\'s sample leaves exactly the listed images', function () {
        Queue::fake([FetchProductTranslations::class]);
        deliverSalla(sallaEvent('product.created', sallaProduct(7, ['images' => [['id' => 1, 'type' => 'image', 'url' => 'https://cdn.salla.sa/1.jpg'], ['id' => 2, 'type' => 'image', 'url' => 'https://cdn.salla.sa/2.jpg'], ['id' => 3, 'type' => 'image', 'url' => 'https://cdn.salla.sa/3.jpg']]]), 0));

        deliverSalla(sallaEvent('product.image.updated', imagePayload([2, 4]), 1))->assertOk();

        expect(ProductImage::orderBy('salla_image_id')->pluck('salla_image_id')->all())->toBe([2, 4]);
    });

    test('a tombstoned image id stays hidden through webhooks and re-syncs', function () {
        Queue::fake([FetchProductTranslations::class]);
        deliverSalla(sallaEvent('product.created', sallaProduct(7, ['images' => [['id' => 1, 'type' => 'image', 'url' => 'https://cdn.salla.sa/1.jpg'], ['id' => 2, 'type' => 'image', 'url' => 'https://cdn.salla.sa/2.jpg']]]), 0));
        ProductImage::where('salla_image_id', 2)->update(['tombstoned_at' => now()]);

        deliverSalla(sallaEvent('product.image.updated', imagePayload([1, 2]), 1));
        deliverSalla(sallaEvent('product.updated', sallaProduct(7, ['images' => [['id' => 1, 'type' => 'image', 'url' => 'https://cdn.salla.sa/1.jpg'], ['id' => 2, 'type' => 'image', 'url' => 'https://cdn.salla.sa/2.jpg']]]), 2));

        expect(ProductImage::whereNull('tombstoned_at')->pluck('salla_image_id')->all())->toBe([1])
            ->and(ProductImage::where('salla_image_id', 2)->sole()->isTombstoned())->toBeTrue();
    });

    test('an image change marks the product context stale', function () {
        Queue::fake([FetchProductTranslations::class]);
        deliverSalla(sallaEvent('product.created', sallaProduct(7), 0));
        ProductContext::query()->update(['stale' => false]);

        deliverSalla(sallaEvent('product.image.updated', imagePayload([71, 99]), 1));

        expect(ProductContext::sole()->stale)->toBeTrue();
    });

    test('an image event for an unknown product is ignored', function () {
        deliverSalla(sallaEvent('product.image.updated', imagePayload([1]), 0))->assertOk();

        expect(ProductImage::count())->toBe(0);
    });

    test('image updates keep the link to a generated image', function () {
        Queue::fake([FetchProductTranslations::class]);
        deliverSalla(sallaEvent('product.created', sallaProduct(7, ['images' => [['id' => 5, 'type' => 'image', 'url' => 'https://cdn.salla.sa/5.jpg']]]), 0));
        ProductImage::where('salla_image_id', 5)->update(['generated_image_id' => 42]);

        deliverSalla(sallaEvent('product.image.updated', imagePayload([5]), 1));

        expect(ProductImage::sole()->generated_image_id)->toBe(42);
    });
});

describe('re-sync and full re-sync', function () {
    test('re-sync re-fetches every language and images at no cost', function () {
        $this->merchant->update(['enabled_languages' => ['ar', 'en']]);
        $service = app(ProductSyncService::class);
        $product = $service->upsert($this->merchant, sallaProduct(7));
        ProductContext::query()->update(['stale' => false]);
        Http::fake(['api.salla.dev/admin/v2/products/7' => function (Request $request) {
            return Http::response(['data' => sallaProduct(7, ['name' => $request->hasHeader('Accept-Language', 'en') ? 'English name' : 'اسم جديد', 'price' => ['amount' => 70]])]);
        }]);

        ResyncProduct::dispatchSync(SALLA_TEST_MERCHANT, $product->id);

        expect(ProductTranslation::where('lang', 'ar')->sole()->name)->toBe('اسم جديد')->and(ProductTranslation::where('lang', 'en')->sole()->name)->toBe('English name')
            ->and((float) $product->fresh()->price)->toBe(70.0)->and(ProductContext::sole()->stale)->toBeTrue()
            ->and(CreditTransaction::count())->toBe(0);
    });

    test('re-sync of a product that no longer exists in Salla deletes it', function () {
        $product = app(ProductSyncService::class)->upsert($this->merchant, sallaProduct(7));
        Http::fake(['api.salla.dev/admin/v2/products/7' => Http::response(['error' => 'not found'], 404)]);

        ResyncProduct::dispatchSync(SALLA_TEST_MERCHANT, $product->id);

        expect(Product::count())->toBe(0);
    });

    test('a full re-sync restores earlier data and marks products missing from Salla as deleted', function () {
        $service = app(ProductSyncService::class);
        $kept = $service->upsert($this->merchant, sallaProduct(1), syncedAt: now()->subDays(10));
        $gone = $service->upsert($this->merchant, sallaProduct(99), syncedAt: now()->subDays(10));
        fakeCatalog(2);

        InitialProductSync::dispatchSync(SALLA_TEST_MERCHANT, true);

        expect(Product::pluck('salla_product_id')->sort()->values()->all())->toBe([1, 2])
            ->and(Product::onlyTrashed()->pluck('salla_product_id')->all())->toBe([99])
            ->and($kept->fresh()->synced_at->gte(now()->subMinute()))->toBeTrue();
    });

    test('an ordinary initial sync never deletes anything', function () {
        $stale = app(ProductSyncService::class)->upsert($this->merchant, sallaProduct(99), syncedAt: now()->subDays(10));
        fakeCatalog(2);

        InitialProductSync::dispatchSync(SALLA_TEST_MERCHANT);

        expect(Product::pluck('salla_product_id')->sort()->values()->all())->toBe([1, 2, 99])->and($stale->fresh()->trashed())->toBeFalse();
    });
});

describe('Salla API client', function () {
    test('a 429 followed by success succeeds without surfacing an error and honors Retry-After', function () {
        Http::fake(['api.salla.dev/admin/v2/products?per_page=*' => Http::sequence()
            ->push('slow down', 429, ['Retry-After' => '3'])
            ->push(['data' => [sallaProduct(1)], 'pagination' => ['totalPages' => 1]])]);

        $result = app(SallaApi::class)->listProducts($this->merchant, 1);

        expect($result['products'])->toHaveCount(1);
        Http::assertSentCount(2);
        Sleep::assertSlept(fn ($duration) => (int) $duration->totalSeconds === 3, 1);
    });

    test('server errors back off exponentially and stop after five attempts', function () {
        Http::fake(['api.salla.dev/admin/v2/products?per_page=*' => Http::response('boom', 503)]);

        expect(fn () => app(SallaApi::class)->listProducts($this->merchant, 1))->toThrow(RequestException::class);

        Http::assertSentCount(5);
        Sleep::assertSequence([
            Sleep::for(1)->seconds(), Sleep::for(2)->seconds(), Sleep::for(4)->seconds(), Sleep::for(8)->seconds(),
        ]);
    });

    test('a 401 is retried once after refreshing the token', function () {
        $this->merchant->token->update(['expires_at' => now()->addDays(14)]);
        Http::fake([
            'accounts.salla.sa/oauth2/token' => Http::response(['access_token' => 'tok-2', 'refresh_token' => 'ref-2', 'expires_in' => 1209600]),
            'api.salla.dev/admin/v2/products?per_page=*' => Http::sequence()->push('unauthorized', 401)->push(['data' => [], 'pagination' => ['totalPages' => 1]]),
        ]);

        app(SallaApi::class)->listProducts($this->merchant, 1);

        Http::assertSentCount(3);
        Http::assertSent(fn (Request $request) => str_contains($request->url(), 'products') && $request->hasHeader('Authorization', 'Bearer tok-2'));
    });

    test('a second 401 after the refresh is surfaced and not retried again', function () {
        Http::fake([
            'accounts.salla.sa/oauth2/token' => Http::response(['access_token' => 'tok-2', 'refresh_token' => 'ref-2', 'expires_in' => 1209600]),
            'api.salla.dev/admin/v2/products?per_page=*' => Http::response('unauthorized', 401),
        ]);

        expect(fn () => app(SallaApi::class)->listProducts($this->merchant, 1))->toThrow(RequestException::class);

        Http::assertSentCount(3);
    });

    test('a store without a token cannot call Salla', function () {
        $this->merchant->token()->delete();

        expect(fn () => app(SallaApi::class)->listProducts($this->merchant->fresh(), 1))->toThrow(MissingToken::class);
        Http::assertNothingSent();
    });

    test('updates send a partial body in the requested language', function () {
        Http::fake(['api.salla.dev/admin/v2/products/7' => Http::response(['data' => ['id' => 7]])]);

        app(SallaApi::class)->updateProduct($this->merchant, 7, ['name' => 'X', 'metadata' => ['title' => 'T']], 'en');

        Http::assertSent(fn (Request $request) => $request->method() === 'PUT' && $request->hasHeader('Accept-Language', 'en') && $request->data() === ['name' => 'X', 'metadata' => ['title' => 'T']]);
    });

    test('image uploads are multipart with the photo and alt', function () {
        Http::fake(['api.salla.dev/admin/v2/products/7/images' => Http::response(['data' => ['id' => 555, 'url' => 'https://cdn.salla.sa/555.jpg']])]);

        $created = app(SallaApi::class)->uploadProductImage($this->merchant, 7, 'binary', 'edit.png', ['alt' => 'صورة', 'main' => false], 'ar');

        expect($created['id'])->toBe(555);
        Http::assertSent(fn (Request $request) => $request->isMultipart() && collect($request->data())->pluck('name')->contains('photo') && collect($request->data())->firstWhere('name', 'alt')['contents'] === 'صورة');
    });
});
