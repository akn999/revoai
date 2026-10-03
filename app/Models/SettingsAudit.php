<?php

namespace App\Models;

use App\Models\Concerns\BelongsToMerchant;
use Database\Factories\SettingsAuditFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $merchant_id
 * @property int|null $salla_user_id
 * @property string $group
 * @property array<string, mixed>|null $old
 * @property array<string, mixed>|null $new
 * @property Carbon $created_at
 */
#[UseFactory(SettingsAuditFactory::class)]
class SettingsAudit extends Model
{
    /** @use HasFactory<SettingsAuditFactory> */
    use BelongsToMerchant, HasFactory;

    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'old' => 'array',
            'new' => 'array',
            'created_at' => 'datetime',
        ];
    }
}
