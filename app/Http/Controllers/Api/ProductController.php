<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\ResolvesMerchant;
use App\Http\Controllers\Controller;
use App\Models\BulkJob;
use App\Models\ContentGeneration;
use App\Models\ContentVersion;
use App\Models\Product;
use App\Models\ProductTranslation;
use App\Products\BulkContentService;
use App\Products\ContentGenerationService;
use App\Products\ContentReviewService;
use App\Products\FieldLimits;
use App\Products\ProductPushService;
use App\Sync\ResyncProduct;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ProductController extends Controller
{
    use ResolvesMerchant;

    public function index(Request $request): JsonResponse
    {
        $request->validate(['search' => ['nullable', 'string', 'max:100'], 'filter' => ['nullable', Rule::in(['all', 'missing_seo', 'stale'])], 'per_page' => ['nullable', 'integer', 'min:1', 'max:100']]);
        $search = addcslashes((string) $request->query('search', ''), '%_\\');

        $products = Product::query()->with(['translations', 'images' => fn ($q) => $q->whereNull('tombstoned_at')->orderBy('sort')])
            ->when($search !== '', fn (Builder $q) => $q->where(fn (Builder $w) => $w
                ->where('sku', 'like', "%{$search}%")
                ->orWhereHas('translations', fn (Builder $t) => $t->where('name', 'like', "%{$search}%"))))
            ->when($request->query('filter') === 'missing_seo', fn (Builder $q) => $q->whereDoesntHave('translations', fn ($t) => $t->whereNotNull('metadata_title')->where('metadata_title', '!=', '')))
            ->when($request->query('filter') === 'stale', fn (Builder $q) => $q->whereHas('context', fn ($c) => $c->where('stale', true)))
            ->orderByDesc('id')->paginate((int) $request->query('per_page', 25));

        return response()->json($products->through(fn (Product $product) => $this->summary($product))->toArray());
    }

    public function show(Product $product): JsonResponse
    {
        $product->load(['translations', 'images' => fn ($q) => $q->whereNull('tombstoned_at')->orderBy('sort')]);

        return response()->json(['data' => [
            ...$this->summary($product),
            'translations' => $product->translations->keyBy('lang')->map->only(FieldLimits::TEXT_FIELDS)->all(),
            'images' => $product->images->map->only(['id', 'salla_image_id', 'url', 'alt', 'main', 'sort'])->values()->all(),
            'limits' => config('revo.product.field_limits'),
        ]]);
    }

    public function quote(Request $request, ContentGenerationService $generations): JsonResponse
    {
        return response()->json(['data' => ['product_content' => $generations->quote($this->merchant($request)), 'field_regeneration' => $generations->quote($this->merchant($request), 'field')]]);
    }

    public function generate(Request $request, Product $product, ContentGenerationService $generations): JsonResponse
    {
        $data = $request->validate([
            'lang' => ['required', 'string'], 'fields' => ['required', 'array', 'min:1'], 'fields.*' => ['string'],
            'keywords' => ['nullable', 'string', 'max:500'], 'instruction' => ['nullable', 'string', 'max:1000'], 'kind' => ['nullable', Rule::in(['full', 'field'])],
        ]);

        $generation = $generations->request($this->merchant($request), $product, $data['lang'], $data['fields'], $this->sallaUserId($request), [
            'keywords' => $data['keywords'] ?? null, 'instruction' => $data['instruction'] ?? null, 'kind' => $data['kind'] ?? 'full',
        ]);

        return response()->json(['data' => $this->generation($generation)], 202);
    }

    public function resync(Request $request, Product $product): JsonResponse
    {
        ResyncProduct::dispatch($this->merchant($request)->merchant_id, $product->id)->onQueue(config('salla.queue'));

        return response()->json(['data' => ['queued' => true]], 202);
    }

    public function generations(Request $request): JsonResponse
    {
        $request->validate(['status' => ['nullable', 'string'], 'product_id' => ['nullable', 'integer']]);

        $items = ContentGeneration::query()->latest('id')
            ->when($request->string('status')->toString(), fn (Builder $q, string $status) => $q->where('status', $status))
            ->when($request->query('product_id'), fn (Builder $q, mixed $id) => $q->where('product_id', (int) $id))->paginate(25);

        return response()->json($items->through(fn (ContentGeneration $generation) => $this->generation($generation))->toArray());
    }

    public function showGeneration(ContentGeneration $generation): JsonResponse
    {
        return response()->json(['data' => $this->generation($generation)]);
    }

    public function approve(Request $request, ContentGeneration $generation, ContentReviewService $review): JsonResponse
    {
        $data = $request->validate(['fields' => ['nullable', 'array'], 'fields.*' => ['string'], 'edits' => ['nullable', 'array'], 'edits.*' => ['nullable', 'string'], 'acknowledge_outdated' => ['nullable', 'boolean']]);

        $result = $review->approve($generation, $data['fields'] ?? null, $data['edits'] ?? [], $this->merchant($request), $this->sallaUserId($request), (bool) ($data['acknowledge_outdated'] ?? false));

        return response()->json(['data' => $this->generation($result)]);
    }

    public function reject(ContentGeneration $generation, ContentReviewService $review): JsonResponse
    {
        return response()->json(['data' => $this->generation($review->reject($generation))]);
    }

    public function versions(Request $request, Product $product): JsonResponse
    {
        $versions = ContentVersion::query()->where('product_id', $product->id)
            ->when($request->string('lang')->toString(), fn (Builder $q, string $lang) => $q->where('lang', $lang))
            ->when($request->string('field')->toString(), fn (Builder $q, string $field) => $q->where('field', $field))
            ->latest('id')->limit(100)->get(['id', 'lang', 'field', 'value', 'source', 'pushed_at', 'created_at']);

        return response()->json(['data' => $versions]);
    }

    public function revert(Request $request, ContentVersion $version, ProductPushService $push): JsonResponse
    {
        $push->revert($this->merchant($request), $version, $this->sallaUserId($request));

        return response()->json(['data' => ['reverted' => true]]);
    }

    public function estimateBulk(Request $request, BulkContentService $bulk): JsonResponse
    {
        $data = $this->bulkInput($request);

        return response()->json(['data' => $bulk->estimate($this->merchant($request), $data['filter'], $data['languages'])]);
    }

    public function startBulk(Request $request, BulkContentService $bulk): JsonResponse
    {
        $data = $this->bulkInput($request);

        $job = $bulk->start($this->merchant($request), $data['filter'], $data['fields'], $data['languages'], $this->sallaUserId($request));

        return response()->json(['data' => $job->only(['id', 'status', 'total', 'done', 'failed', 'estimate'])], 202);
    }

    public function showBulk(BulkJob $job): JsonResponse
    {
        return response()->json(['data' => $job->only(['id', 'status', 'total', 'done', 'failed', 'estimate'])]);
    }

    public function cancelBulk(BulkJob $job, BulkContentService $bulk): JsonResponse
    {
        return response()->json(['data' => $bulk->cancel($job)->only(['id', 'status', 'total', 'done', 'failed', 'estimate'])]);
    }

    /**
     * @return array<string, mixed>
     */
    private function bulkInput(Request $request): array
    {
        return $request->validate([
            'filter' => ['required', 'array'], 'filter.type' => ['required', Rule::in(['all', 'missing_seo', 'ids'])], 'filter.ids' => ['nullable', 'array'], 'filter.ids.*' => ['integer'],
            'fields' => ['nullable', 'array'], 'fields.*' => ['string'], 'languages' => ['required', 'array', 'min:1'], 'languages.*' => ['string'],
        ]) + ['fields' => []];
    }

    /**
     * @return array<string, mixed>
     */
    private function summary(Product $product): array
    {
        $language = $product->translations->first(fn (ProductTranslation $t): bool => filled($t->name))?->lang;

        return [
            'id' => $product->id, 'salla_product_id' => $product->salla_product_id, 'sku' => $product->sku, 'status' => $product->status,
            'name' => $product->translations->firstWhere('lang', $language)?->name, 'price' => $product->price, 'currency' => $product->currency,
            'image' => $product->relationLoaded('images') ? $product->images->first()?->url : null,
            'has_seo' => $product->translations->contains(fn ($t) => filled($t->metadata_title)),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function generation(ContentGeneration $generation): array
    {
        $review = app(ContentReviewService::class);

        return [
            ...$generation->only(['id', 'product_id', 'lang', 'kind', 'fields', 'status', 'output', 'manual_fields', 'error', 'credits', 'expires_at', 'approved_at']),
            'pending_fields' => $review->pendingFields($generation),
            'outdated' => $review->isOutdated($generation),
            'limits' => config('revo.product.field_limits'),
        ];
    }
}
