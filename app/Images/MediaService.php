<?php

namespace App\Images;

use App\Images\Exceptions\InvalidImage;
use App\Models\GeneratedImage;
use App\Models\MediaFolder;
use App\Models\MediaTag;
use App\Models\Merchant;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductImageAlt;
use App\Products\Exceptions\ReviewRejected;
use App\Sync\SallaApi;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;

/**
 * The media library (FR-MED-*): approve drafts into the library, organize with folders and tags,
 * attach to products, delete with tombstones, and expire old files.
 */
class MediaService
{
    public function __construct(private ImageStore $store, private ImageSanitizer $sanitizer, private SallaApi $api) {}

    public function approve(GeneratedImage $image): GeneratedImage
    {
        if ($image->status !== GeneratedImage::DRAFT) {
            throw new ReviewRejected('Only drafts can be approved.');
        }

        $image->forceFill(['status' => GeneratedImage::APPROVED, 'approved_at' => now()])->save();

        return $image;
    }

    public function discard(GeneratedImage $image): GeneratedImage
    {
        if ($image->isAttached()) {
            throw new ReviewRejected('This image is attached to a product. Delete it from the library instead.');
        }

        $this->store->delete($image);
        $image->forceFill(['status' => GeneratedImage::DISCARDED, 'path' => null])->save();

        return $image;
    }

    /**
     * Merchant uploads: sniffed, size-capped and stripped of metadata before they are stored.
     *
     * @throws InvalidImage
     */
    public function upload(Merchant $merchant, string $bytes, string $name, ?MediaFolder $folder = null): GeneratedImage
    {
        $clean = $this->sanitizer->clean($bytes);
        $file = $this->store->put($merchant->merchant_id, $clean['contents'], $clean['extension']);

        return GeneratedImage::query()->create([
            'merchant_id' => $merchant->merchant_id, 'folder_id' => $folder?->id, 'name' => Str::limit(basename($name), 200, ''),
            'source' => 'upload', ...$file, 'mime' => $clean['mime'], 'bytes' => strlen($clean['contents']),
            'status' => GeneratedImage::APPROVED, 'approved_at' => now(), 'expires_at' => now()->addDays((int) config('revo.limits.image_days')),
        ]);
    }

    /**
     * Upload an approved image to the product in Salla and keep the link (FR-MED-005).
     * An attached image no longer expires.
     */
    public function attach(Merchant $merchant, GeneratedImage $image, Product $product, string $language, ?string $alt = null, bool $main = false): ProductImage
    {
        if ($image->status !== GeneratedImage::APPROVED) {
            throw new ReviewRejected('Approve the image before attaching it.');
        }

        $contents = $this->store->contents($image) ?? throw new ReviewRejected('The image file is no longer available.');
        $alt ??= $this->storedAlt($product, $language);
        $extension = pathinfo((string) $image->path, PATHINFO_EXTENSION);

        $created = $this->api->uploadProductImage($merchant, $product->salla_product_id, $contents, "revo-{$image->id}.{$extension}", array_filter(['alt' => $alt, 'main' => $main ? 1 : 0], fn ($value) => $value !== null), $language);

        $record = ProductImage::query()->updateOrCreate(
            ['product_id' => $product->id, 'salla_image_id' => (int) $created['id']],
            ['merchant_id' => $merchant->merchant_id, 'url' => (string) ($created['url'] ?? ''), 'alt' => $alt, 'main' => $main, 'sort' => (int) ($created['sort'] ?? 0), 'generated_image_id' => $image->id, 'tombstoned_at' => null],
        );

        $image->forceFill(['attached_product_id' => $product->id, 'attached_salla_image_id' => (int) $created['id'], 'product_id' => $product->id, 'expires_at' => null])->save();
        $product->context?->forceFill(['stale' => true])->save();

        return $record;
    }

    /**
     * Remove a product image from Revo's view for good: a tombstone keeps webhooks and re-syncs from bringing it back.
     */
    public function tombstone(ProductImage $image): void
    {
        $image->forceFill(['tombstoned_at' => now()])->save();
        $image->product?->context?->forceFill(['stale' => true])->save();
    }

    /**
     * Delete a library image. If it is attached, the product image link is tombstoned too.
     */
    public function delete(GeneratedImage $image): void
    {
        ProductImage::query()->where('generated_image_id', $image->id)->get()->each(fn (ProductImage $record) => $this->tombstone($record));

        $this->store->delete($image);
        $image->delete();
    }

    public function move(GeneratedImage $image, ?MediaFolder $folder): GeneratedImage
    {
        $image->forceFill(['folder_id' => $folder?->id])->save();

        return $image;
    }

    /**
     * @param  array<int, string>  $names
     */
    public function tag(Merchant $merchant, GeneratedImage $image, array $names): GeneratedImage
    {
        $ids = collect($names)->map(fn (string $name) => trim($name))->filter()->unique()
            ->map(fn (string $name) => MediaTag::query()->firstOrCreate(['merchant_id' => $merchant->merchant_id, 'name' => Str::limit($name, 60, '')])->id)->all();

        $image->tags()->sync($ids);

        return $image;
    }

    /**
     * Library listing with filters (FR-MED-003): folder, tag, product, source, status, name search.
     *
     * @param  array{folder_id?: int|null, tag?: string|null, product_id?: int|null, source?: string|null, status?: string|null, search?: string|null, attached?: bool|null}  $filters
     * @return Builder<GeneratedImage>
     */
    public function library(array $filters = []): Builder
    {
        $query = GeneratedImage::query()->whereIn('status', [GeneratedImage::APPROVED, GeneratedImage::DRAFT])->latest('id');

        return $query
            ->when($filters['status'] ?? null, fn (Builder $q, string $status) => $q->where('status', $status))
            ->when($filters['folder_id'] ?? null, fn (Builder $q, int $id) => $q->where('folder_id', $id))
            ->when($filters['product_id'] ?? null, fn (Builder $q, int $id) => $q->where('product_id', $id))
            ->when($filters['source'] ?? null, fn (Builder $q, string $source) => $q->where('source', $source))
            ->when($filters['tag'] ?? null, fn (Builder $q, string $tag) => $q->whereHas('tags', fn (Builder $t) => $t->where('name', $tag)))
            ->when($filters['search'] ?? null, fn (Builder $q, string $search) => $q->where('name', 'like', '%'.addcslashes($search, '%_\\').'%'))
            ->when(($filters['attached'] ?? null) !== null, fn (Builder $q) => $filters['attached'] ? $q->whereNotNull('attached_product_id') : $q->whereNull('attached_product_id'));
    }

    /**
     * Delete the files of images past their retention (FR-MED-006). Attached images never expire.
     */
    public function expire(): int
    {
        $count = 0;

        GeneratedImage::query()->withoutGlobalScopes()
            ->whereIn('status', [GeneratedImage::DRAFT, GeneratedImage::APPROVED])
            ->whereNull('attached_product_id')->whereNotNull('expires_at')->where('expires_at', '<', now())
            ->each(function (GeneratedImage $image) use (&$count): void {
                $this->store->delete($image);
                $image->forceFill(['status' => GeneratedImage::EXPIRED, 'path' => null])->save();
                $count++;
            });

        return $count;
    }

    /**
     * Images that will expire within the warning window, grouped by store, for the digest mail.
     *
     * @return \Illuminate\Support\Collection<int, Collection<int, GeneratedImage>>
     */
    public function expiringSoon(): \Illuminate\Support\Collection
    {
        return GeneratedImage::query()->withoutGlobalScopes()
            ->whereIn('status', [GeneratedImage::DRAFT, GeneratedImage::APPROVED])
            ->whereNull('attached_product_id')
            ->whereBetween('expires_at', [now(), now()->addDays((int) config('revo.limits.expiry_warning_days'))])
            ->get()->groupBy('merchant_id');
    }

    private function storedAlt(Product $product, string $language): ?string
    {
        $imageIds = ProductImage::query()->where('product_id', $product->id)->pluck('id');

        return ProductImageAlt::query()->whereIn('product_image_id', $imageIds)->where('lang', $language)->latest('id')->value('alt');
    }
}
