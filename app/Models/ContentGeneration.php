<?php

namespace App\Models;

use App\Models\Concerns\BelongsToMerchant;
use Database\Factories\ContentGenerationFactory;
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
 * @property string $kind
 * @property array<int, mixed> $fields
 * @property string $status
 * @property int|null $ai_model_id
 * @property array<string, mixed>|null $output
 * @property array<int, mixed>|null $manual_fields
 * @property string|null $instruction
 * @property string|null $keywords
 * @property string|null $error
 * @property int $credits
 * @property int|null $reservation_id
 * @property int|null $bulk_job_id
 * @property int|null $salla_user_id
 * @property string|null $source_hash
 * @property Carbon|null $expires_at
 * @property Carbon|null $approved_at
 */
#[UseFactory(ContentGenerationFactory::class)]
class ContentGeneration extends Model
{
    /** @use HasFactory<ContentGenerationFactory> */
    use BelongsToMerchant, HasFactory;

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'fields' => 'array',
            'output' => 'array',
            'manual_fields' => 'array',
            'expires_at' => 'datetime',
            'approved_at' => 'datetime',
        ];
    }

    public const QUEUED = 'queued';

    public const RUNNING = 'running';

    public const DRAFT = 'draft';

    public const APPROVED = 'approved';

    public const REJECTED = 'rejected';

    public const FAILED = 'failed';

    public const BLOCKED = 'blocked';

    public const EXPIRED = 'expired';

    public const CANCELED = 'canceled';

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * @return BelongsTo<BulkJob, $this>
     */
    public function bulkJob(): BelongsTo
    {
        return $this->belongsTo(BulkJob::class);
    }

    /**
     * Fields already pushed (or acknowledged, for alt text) from this generation.
     *
     * @return array<int, string>
     */
    public function approvedFields(): array
    {
        $pushed = ContentVersion::query()
            ->where('generation_id', $this->id)
            ->whereNotNull('pushed_at')
            ->pluck('field')
            ->all();

        return array_values(array_unique([...$pushed, ...((array) data_get($this->output, '_alt_approved') ? ['alt'] : [])]));
    }

    public function markAltApproved(): void
    {
        $output = (array) $this->output;
        $output['_alt_approved'] = true;
        $this->forceFill(['output' => $output])->save();
    }

    public function isReviewable(): bool
    {
        return $this->status === self::DRAFT;
    }
}
