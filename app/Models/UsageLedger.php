<?php

namespace App\Models;

use Database\Factories\UsageLedgerFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * One row per provider call, charged or not. Never holds prompts or outputs (FR-AI-005).
 *
 * @property int $id
 * @property int $merchant_id
 * @property int|null $salla_user_id
 * @property string $feature
 * @property string $action
 * @property string $provider
 * @property int|null $ai_model_id
 * @property string $provider_model_id
 * @property string|null $provider_request_id
 * @property int $input_tokens
 * @property int $output_tokens
 * @property int $images
 * @property string $provider_cost_usd
 * @property int $credits_charged
 * @property bool $internal
 * @property string $status
 * @property int $latency_ms
 * @property string|null $reference_type
 * @property string|null $reference_id
 * @property Carbon $created_at
 */
#[UseFactory(UsageLedgerFactory::class)]
class UsageLedger extends Model
{
    /** @use HasFactory<UsageLedgerFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    protected $table = 'usage_ledger';

    protected $guarded = ['id'];

    protected static function booted(): void
    {
        static::updating(function (): void {
            throw new LogicException('Usage ledger rows are append-only.');
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'merchant_id' => 'integer',
            'internal' => 'boolean',
            'provider_cost_usd' => 'decimal:6',
        ];
    }

    /**
     * @return BelongsTo<AiModel, $this>
     */
    public function model(): BelongsTo
    {
        return $this->belongsTo(AiModel::class, 'ai_model_id');
    }
}
