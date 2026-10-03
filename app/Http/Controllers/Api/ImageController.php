<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\ResolvesMerchant;
use App\Http\Controllers\Controller;
use App\Images\ImageEditService;
use App\Images\ImageStore;
use App\Models\GeneratedImage;
use App\Models\ImageGeneration;
use App\Models\Preset;
use App\Models\Product;
use App\Models\ProductImage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ImageController extends Controller
{
    use ResolvesMerchant;

    public function quote(Request $request, ImageEditService $images): JsonResponse
    {
        $data = $request->validate(['preset_id' => ['nullable', 'integer'], 'variants' => ['nullable', 'integer', 'min:1', 'max:'.config('revo.images.max_variants')]]);
        $preset = isset($data['preset_id']) ? Preset::query()->availableTo($this->merchant($request)->merchant_id)->whereKey((int) $data['preset_id'])->first() : null;

        return response()->json(['data' => $images->quote($this->merchant($request), $preset, (int) ($data['variants'] ?? 1))]);
    }

    public function edit(Request $request, Product $product, ProductImage $image, ImageEditService $images): JsonResponse
    {
        abort_unless($image->product_id === $product->id, 404);

        $data = $request->validate([
            'preset_id' => ['nullable', 'integer'], 'instruction' => ['nullable', 'string', 'max:2000'],
            'variants' => ['nullable', 'integer'], 'params' => ['nullable', 'array'],
        ]);

        $generation = $images->request($this->merchant($request), $product, $image, $this->sallaUserId($request), $data);

        return response()->json(['data' => $this->generation($generation)], 202);
    }

    public function show(ImageGeneration $generation): JsonResponse
    {
        return response()->json(['data' => $this->generation($generation)]);
    }

    /**
     * @return array<string, mixed>
     */
    private function generation(ImageGeneration $generation): array
    {
        $store = app(ImageStore::class);

        return [
            ...$generation->only(['id', 'product_id', 'status', 'variants', 'credits', 'error', 'instruction']),
            'images' => GeneratedImage::query()->where('generation_id', $generation->id)->get()->map(fn (GeneratedImage $image) => [
                'id' => $image->id, 'status' => $image->status, 'url' => $image->path ? $store->signedUrl($image) : null,
            ])->all(),
        ];
    }
}
