<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\ResolvesMerchant;
use App\Http\Controllers\Controller;
use App\Images\ImageStore;
use App\Images\MediaService;
use App\Models\GeneratedImage;
use App\Models\MediaFolder;
use App\Models\MediaTag;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

class MediaController extends Controller
{
    use ResolvesMerchant;

    public function __construct(private MediaService $media, private ImageStore $store) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'folder_id' => ['nullable', 'integer'], 'tag' => ['nullable', 'string'], 'product_id' => ['nullable', 'integer'], 'source' => ['nullable', 'in:generated,upload'],
            'status' => ['nullable', 'in:draft,approved'], 'search' => ['nullable', 'string', 'max:100'], 'attached' => ['nullable', 'boolean'],
        ]);

        $page = $this->media->library($filters)->with(['tags', 'folder'])->paginate(30);

        return response()->json($page->through(fn (GeneratedImage $image) => $this->present($image))->toArray());
    }

    public function upload(Request $request): JsonResponse
    {
        $request->validate(['file' => ['required', 'file'], 'folder_id' => ['nullable', 'integer']]);
        $folder = $request->integer('folder_id') ? MediaFolder::query()->findOrFail($request->integer('folder_id')) : null;
        $file = $request->file('file');

        $image = $this->media->upload($this->merchant($request), (string) file_get_contents($file->getRealPath()), $file->getClientOriginalName(), $folder);

        return response()->json(['data' => $this->present($image)], 201);
    }

    public function approve(GeneratedImage $image): JsonResponse
    {
        return response()->json(['data' => $this->present($this->media->approve($image))]);
    }

    public function discard(GeneratedImage $image): JsonResponse
    {
        return response()->json(['data' => $this->present($this->media->discard($image))]);
    }

    public function attach(Request $request, GeneratedImage $image): JsonResponse
    {
        $data = $request->validate(['product_id' => ['required', 'integer'], 'lang' => ['required', 'string'], 'alt' => ['nullable', 'string', 'max:100'], 'main' => ['nullable', 'boolean']]);
        $product = Product::query()->whereKey((int) $data['product_id'])->firstOrFail();

        $record = $this->media->attach($this->merchant($request), $image, $product, $data['lang'], $data['alt'] ?? null, (bool) ($data['main'] ?? false));

        return response()->json(['data' => $record->only(['id', 'salla_image_id', 'url', 'alt'])]);
    }

    public function move(Request $request, GeneratedImage $image): JsonResponse
    {
        $data = $request->validate(['folder_id' => ['nullable', 'integer']]);
        $folder = $data['folder_id'] ?? null ? MediaFolder::query()->whereKey((int) $data['folder_id'])->firstOrFail() : null;

        return response()->json(['data' => $this->present($this->media->move($image, $folder))]);
    }

    public function tag(Request $request, GeneratedImage $image): JsonResponse
    {
        $data = $request->validate(['tags' => ['present', 'array', 'max:20'], 'tags.*' => ['string', 'max:60']]);

        return response()->json(['data' => $this->present($this->media->tag($this->merchant($request), $image, $data['tags']))]);
    }

    public function destroy(GeneratedImage $image): Response
    {
        $this->media->delete($image);

        return response()->noContent();
    }

    public function folders(): JsonResponse
    {
        return response()->json(['data' => ['folders' => MediaFolder::query()->orderBy('name')->get(['id', 'name']), 'tags' => MediaTag::query()->orderBy('name')->get(['id', 'name'])]]);
    }

    public function createFolder(Request $request): JsonResponse
    {
        $merchantId = $this->merchant($request)->merchant_id;
        $data = $request->validate(['name' => ['required', 'string', 'max:100', Rule::unique('media_folders')->where('merchant_id', $merchantId)]]);

        return response()->json(['data' => MediaFolder::query()->create(['merchant_id' => $merchantId, 'name' => $data['name']])->only(['id', 'name'])], 201);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(GeneratedImage $image): array
    {
        return [
            ...$image->only(['id', 'name', 'source', 'status', 'mime', 'bytes', 'folder_id', 'product_id', 'attached_product_id', 'expires_at', 'approved_at', 'parent_id']),
            'url' => $image->path ? $this->store->signedUrl($image) : null,
            'tags' => $image->tags->pluck('name')->all(),
        ];
    }
}
