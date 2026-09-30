<?php

namespace App\Models;

use App\Models\Concerns\LogsActivity;
use Database\Factories\PlanFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $slug
 * @property string $name
 * @property string $item_type
 * @property string|null $salla_plan_name
 * @property string|null $salla_item_slug
 * @property int $is_active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[UseFactory(PlanFactory::class)]
class Plan extends Model
{
    /** @use HasFactory<PlanFactory> */
    use HasFactory, LogsActivity;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    /**
     * @return HasMany<PlanPrice, $this>
     */
    public function prices(): HasMany
    {
        return $this->hasMany(PlanPrice::class);
    }

    /**
     * @return HasMany<PlanFeature, $this>
     */
    public function features(): HasMany
    {
        return $this->hasMany(PlanFeature::class);
    }

    /**
     * @return HasMany<Subscription, $this>
     */
    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    /**
     * Map what Salla sold (plan name or addon slug) to a local plan, if any.
     */
    public static function matchPayload(string $itemType, ?string $planName, ?string $itemSlug): ?self
    {
        $query = static::query()->where('is_active', true)->where('item_type', $itemType);

        if ($itemType === 'addon') {
            return $itemSlug ? $query->where('salla_item_slug', $itemSlug)->first() : null;
        }

        return $planName ? $query->where('salla_plan_name', $planName)->first() : null;
    }
}
