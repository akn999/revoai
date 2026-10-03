<?php

namespace App\Overview;

use App\Billing\WalletService;
use App\Models\ContentGeneration;
use App\Models\GeneratedImage;
use App\Models\ImageGeneration;
use App\Models\Merchant;
use App\Models\Product;
use App\Models\UsageLedger;
use App\Platform\RevoSettings;

/**
 * The numbers on the merchant's Overview tab (FR-OVR-*), all computed from the tenant's own rows.
 */
class OverviewService
{
    public function __construct(private WalletService $wallets, private RevoSettings $settings) {}

    /**
     * @return array<string, mixed>
     */
    public function metrics(Merchant $merchant): array
    {
        $wallet = $this->wallets->walletFor($merchant->merchant_id);
        $monthStart = now()->startOfMonth();
        $available = $wallet->available();

        $spent = UsageLedger::query()->where('merchant_id', $merchant->merchant_id)->where('internal', false)->where('created_at', '>=', $monthStart);

        return [
            'status' => $merchant->status->value,
            'sync' => [
                'pages_done' => $merchant->sync_pages_done, 'total_pages' => $merchant->sync_total_pages,
                'finished' => $merchant->sync_finished_at !== null,
            ],
            'credits' => [
                'balance' => $wallet->balance, 'reserved' => $wallet->reserved, 'available' => $available,
                'low' => $available <= $this->settings->lowBalanceThreshold(),
                'threshold' => $this->settings->lowBalanceThreshold(),
                'spent_this_month' => (int) $spent->sum('credits_charged'),
            ],
            'products' => [
                'total' => Product::query()->count(),
                'missing_seo' => Product::query()->whereDoesntHave('translations', fn ($q) => $q->whereNotNull('metadata_title')->where('metadata_title', '!=', ''))->count(),
                'stale_context' => Product::query()->whereHas('context', fn ($q) => $q->where('stale', true))->count(),
            ],
            'content' => [
                'drafts_waiting' => ContentGeneration::query()->where('status', ContentGeneration::DRAFT)->count(),
                'approved_this_month' => ContentGeneration::query()->where('status', ContentGeneration::APPROVED)->where('approved_at', '>=', $monthStart)->count(),
            ],
            'images' => [
                'edits_this_month' => ImageGeneration::query()->where('status', ImageGeneration::COMPLETED)->where('created_at', '>=', $monthStart)->count(),
                'drafts_waiting' => GeneratedImage::query()->where('status', GeneratedImage::DRAFT)->count(),
                'library' => GeneratedImage::query()->where('status', GeneratedImage::APPROVED)->count(),
                'expiring_soon' => GeneratedImage::query()->whereIn('status', [GeneratedImage::DRAFT, GeneratedImage::APPROVED])->whereNull('attached_product_id')
                    ->whereBetween('expires_at', [now(), now()->addDays((int) config('revo.limits.expiry_warning_days'))])->count(),
            ],
            'plan' => ['code' => $merchant->plan_code, 'status' => $merchant->plan_status, 'features' => collect(config('revo.features'))->mapWithKeys(fn (string $feature) => [$feature => $merchant->planAllows($feature)])->all()],
        ];
    }
}
