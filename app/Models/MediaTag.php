<?php

namespace App\Models;

use App\Models\Concerns\BelongsToMerchant;
use Database\Factories\MediaTagFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $merchant_id
 * @property string $name
 */
#[UseFactory(MediaTagFactory::class)]
class MediaTag extends Model
{
    /** @use HasFactory<MediaTagFactory> */
    use BelongsToMerchant, HasFactory;

    protected $guarded = ['id'];
}
