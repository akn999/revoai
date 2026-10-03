<?php

use App\Models\BulkJob;
use App\Models\ContentGeneration;
use App\Models\ContentVersion;
use App\Models\FormDraft;
use App\Models\GeneratedImage;
use App\Models\ImageAnalysis;
use App\Models\ImageGeneration;
use App\Models\MediaFolder;
use App\Models\MediaTag;
use App\Models\Merchant;
use App\Models\Preset;
use App\Models\Product;
use App\Models\ProductContext;
use App\Models\ProductImage;
use App\Models\ProductImageAlt;
use App\Models\ProductTranslation;
use App\Models\StoreContext;
use App\Models\StoreContextEntry;
use App\Models\StorePrompt;
use App\Models\StoreSetting;
use App\Platform\TenantPurger;
use App\Support\CurrentMerchant;
use Illuminate\Database\Eloquent\Model;

/**
 * @return array<string, Closure(int): Model>
 */
function tenantRowMakers(): array
{
    $product = fn (int $m) => Product::factory()->create(['merchant_id' => $m]);

    return [
        Product::class => fn (int $m) => $product($m),
        ProductTranslation::class => fn (int $m) => ProductTranslation::factory()->create(['merchant_id' => $m, 'product_id' => $product($m)->id]),
        ProductContext::class => fn (int $m) => ProductContext::factory()->create(['merchant_id' => $m, 'product_id' => $product($m)->id]),
        ProductImage::class => fn (int $m) => ProductImage::factory()->create(['merchant_id' => $m, 'product_id' => $product($m)->id]),
        ProductImageAlt::class => fn (int $m) => ProductImageAlt::factory()->create(['merchant_id' => $m, 'product_image_id' => ProductImage::factory()->create(['merchant_id' => $m, 'product_id' => $product($m)->id])->id]),
        ContentGeneration::class => fn (int $m) => ContentGeneration::factory()->create(['merchant_id' => $m, 'product_id' => $product($m)->id]),
        ContentVersion::class => fn (int $m) => ContentVersion::factory()->create(['merchant_id' => $m, 'product_id' => $product($m)->id]),
        BulkJob::class => fn (int $m) => BulkJob::factory()->create(['merchant_id' => $m]),
        ImageGeneration::class => fn (int $m) => ImageGeneration::factory()->create(['merchant_id' => $m]),
        GeneratedImage::class => fn (int $m) => GeneratedImage::factory()->create(['merchant_id' => $m]),
        MediaFolder::class => fn (int $m) => MediaFolder::factory()->create(['merchant_id' => $m]),
        MediaTag::class => fn (int $m) => MediaTag::factory()->create(['merchant_id' => $m]),
        ImageAnalysis::class => fn (int $m) => ImageAnalysis::factory()->create(['merchant_id' => $m]),
        FormDraft::class => fn (int $m) => FormDraft::factory()->create(['merchant_id' => $m]),
        StoreContext::class => fn (int $m) => StoreContext::factory()->create(['merchant_id' => $m]),
        StoreContextEntry::class => fn (int $m) => StoreContextEntry::factory()->create(['merchant_id' => $m]),
        StorePrompt::class => fn (int $m) => StorePrompt::factory()->create(['merchant_id' => $m]),
        StoreSetting::class => fn (int $m) => StoreSetting::factory()->create(['merchant_id' => $m]),
        Preset::class => fn (int $m) => Preset::factory()->create(['merchant_id' => $m]),
    ];
}

beforeEach(function () {
    $this->a = Merchant::factory()->active()->create();
    $this->b = Merchant::factory()->active()->create();
});

test('every tenant model with a global scope only sees the current store', function (string $class) {
    $makers = tenantRowMakers();
    $makers[$class]($this->a->merchant_id);
    $theirs = $makers[$class]($this->b->merchant_id);

    app(CurrentMerchant::class)->set($this->a->merchant_id);
    $visible = $class::query()->pluck('merchant_id')->unique()->all();

    expect($visible)->toBe([$this->a->merchant_id])->and($class::query()->find($theirs->getKey()))->toBeNull();
})->with(fn () => collect(array_keys(tenantRowMakers()))->reject(fn ($class) => $class === Preset::class)->mapWithKeys(fn ($class) => [class_basename($class) => $class])->all());

test('every tenant model is listed for purging', function () {
    expect(config('revo.tenant_models'))->toContain(...array_keys(tenantRowMakers()));
});

test('a purge removes every row of the purged store and none of the other store', function () {
    $makers = tenantRowMakers();

    foreach ($makers as $make) {
        $make($this->a->merchant_id);
        $make($this->b->merchant_id);
    }

    app(TenantPurger::class)->purge($this->a);

    foreach (array_keys($makers) as $class) {
        $mine = $class::query()->withoutGlobalScopes()->where('merchant_id', $this->a->merchant_id)->count();
        $theirs = $class::query()->withoutGlobalScopes()->where('merchant_id', $this->b->merchant_id)->count();

        expect([$class, $mine])->toBe([$class, 0])->and($theirs)->toBeGreaterThan(0);
    }
});
