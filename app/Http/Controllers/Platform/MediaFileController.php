<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Models\GeneratedImage;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Serves a library file through a short-lived signed URL; the signature is the access check (FR-MED-009).
 */
class MediaFileController extends Controller
{
    public function __invoke(int $image): StreamedResponse
    {
        $record = GeneratedImage::query()->withoutGlobalScopes()->findOrFail($image);

        abort_unless($record->path && Storage::disk($record->disk)->exists($record->path), 404);

        return Storage::disk($record->disk)->response($record->path, null, ['Content-Type' => $record->mime, 'Cache-Control' => 'private, max-age=300', 'X-Content-Type-Options' => 'nosniff']);
    }
}
