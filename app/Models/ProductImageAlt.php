<?php

namespace App\Models;

use App\Models\Concerns\BelongsToMerchant;
use Database\Factories\ProductImageAltFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $merchant_id
 * @property int $product_image_id
 * @property string $lang
 * @property string $alt
 * @property int|null $generation_id
 */
#[UseFactory(ProductImageAltFactory::class)]
class ProductImageAlt extends Model
{
    /** @use HasFactory<ProductImageAltFactory> */
    use BelongsToMerchant, HasFactory;

    protected $guarded = ['id'];
}
