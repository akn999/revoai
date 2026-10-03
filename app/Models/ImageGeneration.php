<?php

namespace App\Models;

use App\Models\Concerns\BelongsToMerchant;
use Database\Factories\ImageGenerationFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $merchant_id
 * @property int|null $product_id
 * @property int|null $source_image_id
 * @property int|null $parent_generated_image_id
 * @property string $source_url
 * @property string $source_key
 * @property int|null $preset_id
 * @property int|null $ai_model_id
 * @property string|null $instruction
 * @property string|null $composed_prompt
 * @property array<string, mixed>|null $params
 * @property int $variants
 * @property string $status
 * @property string|null $provider_request_id
 * @property int $credits
 * @property int|null $reservation_id
 * @property int|null $salla_user_id
 * @property string|null $error
 * @property Carbon|null $submitted_at
 */
#[UseFactory(ImageGenerationFactory::class)]
class ImageGeneration extends Model
{
    /** @use HasFactory<ImageGenerationFactory> */
    use BelongsToMerchant, HasFactory;

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'params' => 'array',
            'submitted_at' => 'datetime',
        ];
    }

    public const QUEUED = 'queued';

    public const SUBMITTED = 'submitted';

    public const COMPLETED = 'completed';

    public const FAILED = 'failed';

    public const BLOCKED = 'blocked';

    public const TIMED_OUT = 'timed_out';
}
