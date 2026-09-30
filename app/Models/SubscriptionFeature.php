<?php

namespace App\Models;

use App\Models\Concerns\LogsActivity;
use Database\Factories\SubscriptionFeatureFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $subscription_id
 * @property string $feature_key
 * @property int $quantity
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[UseFactory(SubscriptionFeatureFactory::class)]
class SubscriptionFeature extends Model
{
    /** @use HasFactory<SubscriptionFeatureFactory> */
    use HasFactory, LogsActivity;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['feature_key' => 'string'];
    }

    /**
     * @return BelongsTo<Subscription, $this>
     */
    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class)->withoutGlobalScopes();
    }
}
