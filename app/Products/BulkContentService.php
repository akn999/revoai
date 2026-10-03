<?php

namespace App\Products;

use App\Billing\Exceptions\InsufficientCredits;
use App\Billing\WalletService;
use App\Models\BulkJob;
use App\Models\ContentGeneration;
use App\Models\CreditReservation;
use App\Models\Merchant;
use App\Models\Product;
use App\Products\Exceptions\ReviewRejected;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Bulk generation (FR-PRD-016/017): estimate first, reserve the whole estimate up front or start
 * nothing, run each product-language as its own generation, cancel releases what has not started.
 */
class BulkContentService
{
    public function __construct(private ContentGenerationService $generations, private WalletService $wallets) {}

    /**
     * @param  array<string, mixed>  $filter  {type: all|missing_seo|ids, ids?: int[]}
     * @param  array<int, string>  $languages
     * @return array{products: int, units: int, unit_price: int, estimate: int, available: int}
     */
    public function estimate(Merchant $merchant, array $filter, array $languages): array
    {
        $products = $this->query($filter)->count();
        $unitPrice = $this->generations->quote($merchant)['credits'];
        $units = $products * count($languages);

        return [
            'products' => $products,
            'units' => $units,
            'unit_price' => $unitPrice,
            'estimate' => $units * $unitPrice,
            'available' => $this->wallets->walletFor($merchant->merchant_id)->available(),
        ];
    }

    /**
     * @param  array<string, mixed>  $filter
     * @param  array<int, string>  $fields
     * @param  array<int, string>  $languages
     *
     * @throws InsufficientCredits
     * @throws ReviewRejected
     */
    public function start(Merchant $merchant, array $filter, array $fields, array $languages, ?int $sallaUserId = null): BulkJob
    {
        if (! $merchant->planAllows('product_content')) {
            throw new Exceptions\PlanLocked('product_content');
        }

        $languages = array_values(array_intersect($languages, $merchant->enabled_languages ?? config('revo.product.languages')));
        $fields = array_values(array_intersect($fields, FieldLimits::TEXT_FIELDS));

        if ($languages === [] || $fields === []) {
            throw new ReviewRejected('Choose at least one field and language.');
        }

        $productIds = $this->query($filter)->pluck('id')->all();

        if ($productIds === []) {
            throw new ReviewRejected('No products match this selection.');
        }

        $unitPrice = $this->generations->quote($merchant);
        $units = [];

        foreach ($productIds as $productId) {
            foreach ($languages as $language) {
                $units[] = [$productId, $language];
            }
        }

        return DB::transaction(function () use ($merchant, $filter, $fields, $languages, $sallaUserId, $units, $unitPrice): BulkJob {
            $reservations = $this->wallets->reserveMany($merchant->merchant_id, array_fill(0, count($units), $unitPrice['credits']), 'product_content', $unitPrice['source']);

            $job = BulkJob::query()->create([
                'merchant_id' => $merchant->merchant_id, 'salla_user_id' => $sallaUserId, 'filter' => $filter, 'fields' => $fields,
                'languages' => $languages, 'total' => count($units), 'estimate' => count($units) * $unitPrice['credits'], 'status' => BulkJob::RUNNING,
            ]);

            foreach ($units as $index => [$productId, $language]) {
                $generation = $this->generations->request($merchant, Product::query()->whereKey($productId)->firstOrFail(), $language, $fields, $sallaUserId, [
                    'bulk_job_id' => $job->id, 'reservation' => $reservations[$index], 'dispatch' => false,
                ]);

                RunContentGeneration::dispatch($merchant->merchant_id, $generation->id)->onQueue(config('salla.queue'))->afterCommit();
            }

            return $job;
        });
    }

    /**
     * Release everything that has not started; running units finish and keep their charge.
     */
    public function cancel(BulkJob $job): BulkJob
    {
        $queued = ContentGeneration::query()->where('bulk_job_id', $job->id)->where('status', ContentGeneration::QUEUED)->get();

        foreach ($queued as $generation) {
            if ($reservation = CreditReservation::query()->find($generation->reservation_id)) {
                $this->wallets->release($reservation, 'Bulk job canceled');
            }

            $generation->forceFill(['status' => ContentGeneration::CANCELED, 'credits' => 0])->save();
        }

        $job->forceFill(['status' => BulkJob::CANCELED])->save();

        return $this->refresh($job);
    }

    /**
     * Recompute progress from the generations; completes the job when nothing is pending.
     */
    public function refresh(BulkJob $job): BulkJob
    {
        $counts = ContentGeneration::query()->where('bulk_job_id', $job->id)->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status');
        $failed = (int) ($counts[ContentGeneration::FAILED] ?? 0) + (int) ($counts[ContentGeneration::BLOCKED] ?? 0);
        $pending = (int) ($counts[ContentGeneration::QUEUED] ?? 0) + (int) ($counts[ContentGeneration::RUNNING] ?? 0);

        $job->done = $job->total - $pending - (int) ($counts[ContentGeneration::CANCELED] ?? 0) - $failed;
        $job->failed = $failed;

        if ($pending === 0 && $job->status === BulkJob::RUNNING) {
            $job->status = BulkJob::COMPLETED;
        }

        $job->save();

        return $job;
    }

    /**
     * @param  array<string, mixed>  $filter
     * @return Builder<Product>
     */
    private function query(array $filter): Builder
    {
        $query = Product::query();

        return match ($filter['type'] ?? 'all') {
            'ids' => $query->whereIn('id', (array) ($filter['ids'] ?? [])),
            'missing_seo' => $query->whereDoesntHave('translations', fn ($q) => $q->whereNotNull('metadata_title')->where('metadata_title', '!=', '')),
            default => $query,
        };
    }
}
