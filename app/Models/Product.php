<?php

namespace App\Models;

use App\Models\Concerns\BelongsToMerchant;
use Database\Factories\ProductFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $merchant_id
 * @property int $salla_product_id
 * @property string|null $sku
 * @property string|null $mpn
 * @property string|null $gtin
 * @property string|null $type
 * @property string|null $status
 * @property string|null $price
 * @property string|null $sale_price
 * @property string|null $currency
 * @property int|null $quantity
 * @property bool $hide_quantity
 * @property bool $is_available
 * @property array<int, mixed>|null $categories
 * @property array<string, mixed>|null $brand
 * @property array<int, mixed>|null $tags
 * @property array<int, mixed>|null $options
 * @property array<int, mixed>|null $skus
 * @property array<string, mixed>|null $urls
 * @property array<string, mixed>|null $promotion
 * @property array<string, mixed>|null $metadata
 * @property array<string, mixed>|null $raw
 * @property string|null $relevant_hash
 * @property Carbon|null $last_event_at
 * @property Carbon|null $synced_at
 */
#[UseFactory(ProductFactory::class)]
class Product extends Model
{
    /** @use HasFactory<ProductFactory> */
    use BelongsToMerchant, HasFactory, SoftDeletes;

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'salla_product_id' => 'integer',
            'price' => 'decimal:2',
            'sale_price' => 'decimal:2',
            'hide_quantity' => 'boolean',
            'is_available' => 'boolean',
            'categories' => 'array',
            'brand' => 'array',
            'tags' => 'array',
            'options' => 'array',
            'skus' => 'array',
            'urls' => 'array',
            'promotion' => 'array',
            'metadata' => 'array',
            'raw' => 'array',
            'last_event_at' => 'datetime',
            'synced_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<ProductTranslation, $this>
     */
    public function translations(): HasMany
    {
        return $this->hasMany(ProductTranslation::class);
    }

    /**
     * @return HasOne<ProductContext, $this>
     */
    public function context(): HasOne
    {
        return $this->hasOne(ProductContext::class);
    }

    /**
     * @return HasMany<ProductImage, $this>
     */
    public function images(): HasMany
    {
        return $this->hasMany(ProductImage::class);
    }

    public function translation(string $language): ?ProductTranslation
    {
        return $this->translations->firstWhere('lang', $language);
    }
}
