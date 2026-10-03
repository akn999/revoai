<?php

namespace App\Images;

use App\Images\Exceptions\InvalidImage;

/**
 * Upload hardening (NFR-SEC): the type is sniffed from the bytes, never trusted from the filename
 * or client header, size is capped, and metadata (EXIF, text chunks) is stripped before storage.
 */
class ImageSanitizer
{
    private const EXTENSIONS = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];

    /**
     * @return array{contents: string, mime: string, extension: string}
     *
     * @throws InvalidImage
     */
    public function clean(string $bytes): array
    {
        $max = (int) config('revo.limits.upload_megabytes') * 1024 * 1024;

        if ($bytes === '' || strlen($bytes) > $max) {
            throw new InvalidImage('The image is empty or larger than '.config('revo.limits.upload_megabytes').' MB.');
        }

        $mime = (string) (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes);

        if (! in_array($mime, (array) config('revo.images.mimes'), true)) {
            throw new InvalidImage('Only JPEG, PNG and WebP images are accepted.');
        }

        $contents = match ($mime) {
            'image/jpeg' => $this->stripJpeg($bytes),
            'image/png' => $this->stripPng($bytes),
            default => $this->stripWebp($bytes),
        };

        return ['contents' => $contents, 'mime' => $mime, 'extension' => self::EXTENSIONS[$mime]];
    }

    private function stripJpeg(string $bytes): string
    {
        $out = substr($bytes, 0, 2);
        $offset = 2;
        $length = strlen($bytes);

        while ($offset + 4 <= $length) {
            if ($bytes[$offset] !== "\xFF") {
                break;
            }

            $marker = ord($bytes[$offset + 1]);

            if ($marker === 0xDA) {
                return $out.substr($bytes, $offset);
            }

            $size = $this->readInt('n', substr($bytes, $offset + 2, 2));

            if ($size === null || $offset + 2 + $size > $length) {
                break;
            }

            $segment = substr($bytes, $offset, 2 + $size);

            // APP1-APP15 and comments carry EXIF, XMP, ICC extras and free text.
            if (! (($marker >= 0xE1 && $marker <= 0xEF) || $marker === 0xFE)) {
                $out .= $segment;
            }

            $offset += 2 + $size;
        }

        return $out.substr($bytes, $offset);
    }

    private function stripPng(string $bytes): string
    {
        $out = substr($bytes, 0, 8);
        $offset = 8;
        $length = strlen($bytes);

        while ($offset + 12 <= $length) {
            $size = $this->readInt('N', substr($bytes, $offset, 4));

            if ($size === null || $offset + 12 + $size > $length) {
                break;
            }

            $type = substr($bytes, $offset + 4, 4);
            $chunk = substr($bytes, $offset, 12 + $size);

            if (! in_array($type, ['tEXt', 'zTXt', 'iTXt', 'eXIf', 'tIME'], true)) {
                $out .= $chunk;
            }

            $offset += 12 + $size;
        }

        return $out;
    }

    private function stripWebp(string $bytes): string
    {
        if (strlen($bytes) < 12) {
            return $bytes;
        }

        $out = '';
        $offset = 12;
        $length = strlen($bytes);

        while ($offset + 8 <= $length) {
            $type = substr($bytes, $offset, 4);
            $size = $this->readInt('V', substr($bytes, $offset + 4, 4));

            if ($size === null || $offset + 8 + $size > $length) {
                break;
            }

            $chunk = substr($bytes, $offset, 8 + $size + ($size % 2));

            if (! in_array($type, ['EXIF', 'XMP '], true)) {
                $out .= $chunk;
            }

            $offset += 8 + $size + ($size % 2);
        }

        return 'RIFF'.pack('V', strlen($out) + 4).'WEBP'.$out;
    }

    private function readInt(string $format, string $bytes): ?int
    {
        $values = unpack($format, $bytes);

        return $values === false ? null : (int) $values[1];
    }
}
