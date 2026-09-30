<?php

namespace App\Models;

use App\Models\Concerns\BelongsToMerchant;
use App\Models\Concerns\LogsActivity;
use Database\Factories\MerchantTokenFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $merchant_id
 * @property string $access_token
 * @property string $refresh_token
 * @property string $token_type
 * @property string|null $scope
 * @property Carbon $expires_at
 * @property Carbon|null $refreshed_at
 * @property Carbon|null $revoked_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[UseFactory(MerchantTokenFactory::class)]
class MerchantToken extends Model
{
    /** @use HasFactory<MerchantTokenFactory> */
    use BelongsToMerchant, HasFactory, LogsActivity;

    protected $guarded = [];

    protected $hidden = ['access_token', 'refresh_token'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'access_token' => 'encrypted',
            'refresh_token' => 'encrypted',
            'expires_at' => 'datetime',
            'refreshed_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function isExpiring(int $days = 3): bool
    {
        return $this->expires_at->lte(now()->addDays($days));
    }
}
