<?php

namespace App\Models;

use App\Models\Concerns\AuditsAdminChanges;
use Database\Factories\PresetFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int|null $merchant_id
 * @property string $name_ar
 * @property string $name_en
 * @property string $prompt
 * @property int|null $ai_model_id
 * @property array<string, mixed>|null $params
 * @property int|null $price_override
 * @property bool $active
 * @property int $sort
 */
#[UseFactory(PresetFactory::class)]
class Preset extends Model
{
    /** @use HasFactory<PresetFactory> */
    use AuditsAdminChanges, HasFactory;

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'params' => 'array',
            'active' => 'boolean',
        ];
    }

    /**
     * Default presets (no merchant) plus the merchant's own.
     *
     * @param  Builder<Preset>  $query
     * @return Builder<Preset>
     */
    public function scopeAvailableTo(Builder $query, int $merchantId): Builder
    {
        return $query->where(fn (Builder $query) => $query->whereNull('merchant_id')->orWhere('merchant_id', $merchantId));
    }

    /**
     * @param  Builder<Preset>  $query
     * @return Builder<Preset>
     */
    public function scopeDefaults(Builder $query): Builder
    {
        return $query->whereNull('merchant_id');
    }

    /**
     * @return BelongsTo<AiModel, $this>
     */
    public function model(): BelongsTo
    {
        return $this->belongsTo(AiModel::class, 'ai_model_id');
    }

    public function isDefault(): bool
    {
        return $this->merchant_id === null;
    }

    public function name(string $language = 'en'): string
    {
        return $language === 'ar' ? $this->name_ar : $this->name_en;
    }
}
