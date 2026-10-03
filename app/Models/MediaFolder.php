<?php

namespace App\Models;

use App\Models\Concerns\BelongsToMerchant;
use Database\Factories\MediaFolderFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $merchant_id
 * @property string $name
 */
#[UseFactory(MediaFolderFactory::class)]
class MediaFolder extends Model
{
    /** @use HasFactory<MediaFolderFactory> */
    use BelongsToMerchant, HasFactory;

    protected $guarded = ['id'];
}
