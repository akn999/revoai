<?php

namespace App\Images;

use App\Models\GeneratedImage;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

/**
 * Files live on a private disk and are only reachable through short-lived signed URLs (FR-MED-009).
 */
class ImageStore
{
    /**
     * @return array{disk: string, path: string}
     */
    public function put(int $merchantId, string $contents, string $extension): array
    {
        $disk = (string) config('revo.images.disk');
        $path = "media/{$merchantId}/".Str::uuid().".{$extension}";
        Storage::disk($disk)->put($path, $contents);

        return ['disk' => $disk, 'path' => $path];
    }

    public function contents(GeneratedImage $image): ?string
    {
        return $image->path ? Storage::disk($image->disk)->get($image->path) : null;
    }

    public function delete(GeneratedImage $image): void
    {
        if ($image->path) {
            Storage::disk($image->disk)->delete($image->path);
        }
    }

    public function signedUrl(GeneratedImage $image): string
    {
        return URL::temporarySignedRoute('media.file', now()->addMinutes((int) config('revo.limits.signed_url_minutes')), ['image' => $image->id]);
    }
}
