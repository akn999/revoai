<?php

namespace App\Models;

use App\Models\Concerns\BelongsToMerchant;
use Database\Factories\ModerationEventFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $merchant_id
 * @property int|null $salla_user_id
 * @property string $subject_type
 * @property string|null $subject_id
 * @property string|null $category
 * @property string $provider
 * @property string $decision
 * @property Carbon $created_at
 */
#[UseFactory(ModerationEventFactory::class)]
class ModerationEvent extends Model
{
    /** @use HasFactory<ModerationEventFactory> */
    use BelongsToMerchant, HasFactory;

    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
        ];
    }
}
