<?php

namespace App\Models;

use App\Models\Concerns\BelongsToMerchant;
use Database\Factories\ProductTranslationFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $merchant_id
 * @property int $product_id
 * @property string $lang
 * @property string|null $name
 * @property string|null $description
 * @property string|null $promotion_title
 * @property string|null $subtitle
 * @property string|null $metadata_title
 * @property string|null $metadata_description
 * @property string|null $metadata_url
 * @property Carbon|null $fetched_at
 */
#[UseFactory(ProductTranslationFactory::class)]
class ProductTranslation extends Model
{
    /** @use HasFactory<ProductTranslationFactory> */
    use BelongsToMerchant, HasFactory;

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'fetched_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
