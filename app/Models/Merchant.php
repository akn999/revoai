<?php

namespace App\Models;

use App\Enums\MerchantStatus;
use App\Models\Concerns\LogsActivity;
use Database\Factories\MerchantFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $merchant_id
 * @property string|null $name
 * @property string|null $email
 * @property string|null $mobile
 * @property string|null $domain
 * @property string|null $avatar
 * @property string|null $owner_name
 * @property string|null $owner_email
 * @property string|null $store_type
 * @property MerchantStatus $status
 * @property array<int, string>|null $app_scopes
 * @property Carbon|null $installed_at
 * @property Carbon|null $uninstalled_at
 * @property Carbon|null $profile_synced_at
 * @property Carbon|null $last_event_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property string $default_language
 * @property array<int, string>|null $enabled_languages
 * @property string|null $plan_code
 * @property string|null $plan_status
 * @property string|null $salla_plan_ref
 * @property Carbon|null $purge_at
 * @property Carbon|null $last_webhook_at
 * @property bool $reauth_required
 * @property int $sync_pages_done
 * @property int|null $sync_total_pages
 * @property Carbon|null $sync_started_at
 * @property Carbon|null $sync_finished_at
 * @property Carbon|null $deleted_at
 */
#[UseFactory(MerchantFactory::class)]
class Merchant extends Model
{
    /** @use HasFactory<MerchantFactory> */
    use HasFactory, LogsActivity, SoftDeletes;

    /** id is the internal key; merchant_id is the Salla merchant ID used by every relation. */
    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'merchant_id' => 'integer',
            'status' => MerchantStatus::class,
            'app_scopes' => 'array',
            'installed_at' => 'datetime',
            'uninstalled_at' => 'datetime',
            'profile_synced_at' => 'datetime',
            'last_event_at' => 'datetime',
            'enabled_languages' => 'array',
            'purge_at' => 'datetime',
            'last_webhook_at' => 'datetime',
            'reauth_required' => 'boolean',
            'sync_started_at' => 'datetime',
            'sync_finished_at' => 'datetime',
        ];
    }

    public static function findBySallaId(int $merchantId): ?self
    {
        return static::firstWhere('merchant_id', $merchantId);
    }

    /**
     * @return HasOne<MerchantToken, $this>
     */
    /**
     * @return HasOne<MerchantWallet, $this>
     */
    public function wallet(): HasOne
    {
        return $this->hasOne(MerchantWallet::class, 'merchant_id', 'merchant_id');
    }

    public function token(): HasOne
    {
        return $this->hasOne(MerchantToken::class, 'merchant_id', 'merchant_id');
    }

    /**
     * @return HasOne<MerchantSetting, $this>
     */
    public function settings(): HasOne
    {
        return $this->hasOne(MerchantSetting::class, 'merchant_id', 'merchant_id');
    }

    /**
     * @return HasMany<Subscription, $this>
     */
    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class, 'merchant_id', 'merchant_id');
    }

    /**
     * @return HasMany<AppFeedback, $this>
     */
    public function feedback(): HasMany
    {
        return $this->hasMany(AppFeedback::class, 'merchant_id', 'merchant_id');
    }

    /**
     * @return HasMany<AppEvent, $this>
     */
    public function events(): HasMany
    {
        return $this->hasMany(AppEvent::class, 'merchant_id', 'merchant_id');
    }

    /**
     * @return HasOne<Subscription, $this>
     */
    public function currentPlan(): HasOne
    {
        return $this->hasOne(Subscription::class, 'merchant_id', 'merchant_id')
            ->where('item_type', 'plan')->entitled()->latestOfMany('starts_at');
    }

    /**
     * @return HasMany<Subscription, $this>
     */
    public function activeAddons(): HasMany
    {
        return $this->subscriptions()->where('item_type', 'addon')->entitled();
    }

    /**
     * Whether the merchant's current Revo plan includes a feature (FR-BIL-005).
     * Lapsed or missing plans lock plan features but never data or credits.
     */
    public function planAllows(string $feature): bool
    {
        if (! in_array($this->plan_status, ['active', 'trial'], true) || ! $this->plan_code) {
            return false;
        }

        return (bool) data_get(Plan::query()->where('slug', $this->plan_code)->value('feature_flags'), $feature);
    }

    public function canUseApp(): bool
    {
        return $this->status === MerchantStatus::Active;
    }

    public function featureQuantity(string $key): int
    {
        return (int) SubscriptionFeature::query()
            ->whereHas('subscription', fn ($query) => $query->withoutGlobalScopes()
                ->where('merchant_id', $this->merchant_id)->entitled())
            ->where('feature_key', $key)
            ->sum('quantity');
    }

    /**
     * Activate unless the event is older than a lifecycle event already applied (FR-8).
     */
    public function activateFrom(AppEvent $event): void
    {
        if ($this->last_event_at && $event->event_created_at?->lt($this->last_event_at)) {
            return;
        }

        $this->activate();
    }

    public function activate(): void
    {
        $this->forceFill(['status' => MerchantStatus::Active, 'uninstalled_at' => null])->save();
    }
}
