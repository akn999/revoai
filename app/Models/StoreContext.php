<?php

namespace App\Models;

use App\Models\Concerns\BelongsToMerchant;
use Database\Factories\StoreContextFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $merchant_id
 * @property string|null $store_name
 * @property string|null $tagline
 * @property string|null $slogan
 * @property string|null $industry
 * @property array<int, mixed>|null $audience
 * @property string|null $brand_tone
 * @property string|null $photography_style
 * @property array<int, mixed>|null $brand_colors
 * @property string|null $delivery_policy
 * @property string|null $return_policy
 * @property bool $returns_accepted
 * @property string|null $privacy_policy
 * @property array<int, mixed>|null $faq
 */
#[UseFactory(StoreContextFactory::class)]
class StoreContext extends Model
{
    /** @use HasFactory<StoreContextFactory> */
    use BelongsToMerchant, HasFactory;

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'audience' => 'array',
            'brand_colors' => 'array',
            'returns_accepted' => 'boolean',
            'faq' => 'array',
        ];
    }
}
