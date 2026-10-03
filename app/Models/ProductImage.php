<?php

namespace App\Models;

use App\Models\Concerns\BelongsToMerchant;
use Database\Factories\ProductImageFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $merchant_id
 * @property int $product_id
 * @property int $salla_image_id
 * @property string $url
 * @property string|null $alt
 * @property bool $main
 * @property int $sort
 * @property string|null $three_d_image_url
 * @property int|null $generated_image_id
 * @property Carbon|null $tombstoned_at
 */
#[UseFactory(ProductImageFactory::class)]
class ProductImage extends Model
{
    /** @use HasFactory<ProductImageFactory> */
    use BelongsToMerchant, HasFactory;

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'main' => 'boolean',
            'tombstoned_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function isTombstoned(): bool
    {
        return $this->tombstoned_at !== null;
    }
}
