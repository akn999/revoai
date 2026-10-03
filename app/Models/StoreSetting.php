<?php

namespace App\Models;

use App\Models\Concerns\BelongsToMerchant;
use Database\Factories\StoreSettingFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $merchant_id
 * @property array<int, mixed>|null $languages
 * @property bool $auto_publish
 * @property string $description_length
 * @property string $description_structure
 * @property array<string, mixed>|null $models
 * @property array<string, mixed>|null $image_defaults
 * @property int $default_variants
 */
#[UseFactory(StoreSettingFactory::class)]
class StoreSetting extends Model
{
    /** @use HasFactory<StoreSettingFactory> */
    use BelongsToMerchant, HasFactory;

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'languages' => 'array',
            'auto_publish' => 'boolean',
            'models' => 'array',
            'image_defaults' => 'array',
        ];
    }
}
