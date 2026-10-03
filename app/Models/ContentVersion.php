<?php

namespace App\Models;

use App\Models\Concerns\BelongsToMerchant;
use Database\Factories\ContentVersionFactory;
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
 * @property string $field
 * @property string|null $value
 * @property string $source
 * @property int|null $generation_id
 * @property Carbon|null $pushed_at
 * @property int|null $salla_user_id
 */
#[UseFactory(ContentVersionFactory::class)]
class ContentVersion extends Model
{
    /** @use HasFactory<ContentVersionFactory> */
    use BelongsToMerchant, HasFactory;

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'pushed_at' => 'datetime',
        ];
    }

    public const SALLA_ORIGINAL = 'salla_original';

    public const AI = 'ai';

    public const MANUAL_EDIT = 'manual_edit';

    public const REVERT = 'revert';

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
