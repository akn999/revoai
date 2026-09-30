<?php

namespace App\Models;

use App\Models\Concerns\BelongsToMerchant;
use Database\Factories\MerchantSettingFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $merchant_id
 * @property array<string, mixed> $settings
 * @property int|null $updated_from_event_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[UseFactory(MerchantSettingFactory::class)]
class MerchantSetting extends Model
{
    /** @use HasFactory<MerchantSettingFactory> */
    use BelongsToMerchant, HasFactory;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['settings' => 'array'];
    }

    /**
     * @return BelongsTo<AppEvent, $this>
     */
    public function sourceEvent(): BelongsTo
    {
        return $this->belongsTo(AppEvent::class, 'updated_from_event_id');
    }
}
