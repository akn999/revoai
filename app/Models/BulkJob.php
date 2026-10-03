<?php

namespace App\Models;

use App\Models\Concerns\BelongsToMerchant;
use Database\Factories\BulkJobFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $merchant_id
 * @property int|null $salla_user_id
 * @property array<string, mixed> $filter
 * @property array<int, mixed> $fields
 * @property array<int, mixed> $languages
 * @property int $total
 * @property int $done
 * @property int $failed
 * @property int $estimate
 * @property string $status
 */
#[UseFactory(BulkJobFactory::class)]
class BulkJob extends Model
{
    /** @use HasFactory<BulkJobFactory> */
    use BelongsToMerchant, HasFactory;

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'filter' => 'array',
            'fields' => 'array',
            'languages' => 'array',
        ];
    }

    public const RUNNING = 'running';

    public const COMPLETED = 'completed';

    public const CANCELED = 'canceled';

    /**
     * @return HasMany<ContentGeneration, $this>
     */
    public function generations(): HasMany
    {
        return $this->hasMany(ContentGeneration::class);
    }
}
