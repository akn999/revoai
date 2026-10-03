<?php

namespace App\Models;

use App\Models\Concerns\BelongsToMerchant;
use Database\Factories\GeneratedImageFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $merchant_id
 * @property int|null $generation_id
 * @property int|null $product_id
 * @property int|null $parent_id
 * @property int|null $folder_id
 * @property string|null $name
 * @property string $source
 * @property string $disk
 * @property string|null $path
 * @property string $mime
 * @property int $bytes
 * @property string $status
 * @property int|null $attached_product_id
 * @property int|null $attached_salla_image_id
 * @property Carbon|null $approved_at
 * @property Carbon|null $expires_at
 */
#[UseFactory(GeneratedImageFactory::class)]
class GeneratedImage extends Model
{
    /** @use HasFactory<GeneratedImageFactory> */
    use BelongsToMerchant, HasFactory;

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'approved_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    public const DRAFT = 'draft';

    public const APPROVED = 'approved';

    public const DISCARDED = 'discarded';

    public const EXPIRED = 'expired';

    /**
     * @return BelongsToMany<MediaTag, $this>
     */
    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(MediaTag::class, 'media_taggables');
    }

    /**
     * @return BelongsTo<MediaFolder, $this>
     */
    public function folder(): BelongsTo
    {
        return $this->belongsTo(MediaFolder::class);
    }

    public function isAttached(): bool
    {
        return $this->attached_product_id !== null;
    }
}
