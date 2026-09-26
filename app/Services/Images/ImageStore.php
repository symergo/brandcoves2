<?php

declare(strict_types=1);

namespace App\Services\Images;

use App\Services\PageReading\FetchRefused;
use App\Services\PageReading\SafeFetch;
use GdImage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Pictures on hand-written items: ours, re-encoded, never hot-linked.
 *
 * ## Why we copy instead of linking
 *
 * A list is opened by other people from a link. An `<img>` pointing at a host
 * the list owner (or a pasted page) chose makes every one of those browsers
 * report to that host who opened the list and when — an on-by-default tracking
 * pixel, on the one kind of page where the owner is not supposed to learn about
 * activity. That was the reason a manual item showed no picture at all until
 * 2026-09-26. Copying the picture to our own storage keeps the picture and
 * drops the pixel.
 *
 * ## Why every image is re-encoded
 *
 * - A phone photo carries its EXIF, which includes the GPS position it was
 *   taken at: often the person's home. Decoding to pixels and encoding again
 *   leaves every byte of metadata behind.
 * - A file is only ever served as what we encoded it to, so a "picture" that
 *   is really HTML or SVG with a script inside cannot reach a browser as such.
 * - Size: the longest side is capped, so a 12-megapixel photo is not what a
 *   list of forty items downloads.
 *
 * Files live on the `media` disk, which is a persistent volume in production
 * (docker-compose.coolify.yml): the container's own disk is wiped by every
 * deploy.
 */
class ImageStore
{
    public const DISK = 'media';

    /** Longest side after resizing, in pixels. Twice a list card's width. */
    private const MAX_SIDE = 1600;

    /**
     * Refused before decoding: a small file can declare 40000×40000 pixels
     * and decode to gigabytes (a decompression bomb).
     */
    private const MAX_PIXELS = 40_000_000;

    public function __construct(private readonly SafeFetch $fetch) {}

    /** Copy a picture a shop's page pointed at. Null when it cannot be had. */
    public function fromUrl(string $url): ?string
    {
        try {
            $response = $this->fetch->get(
                $url,
                ['image/jpeg', 'image/png', 'image/webp', 'image/gif'],
                (int) config('giftcoves.page_reading.max_image_bytes', 8 * 1024 * 1024),
            );
        } catch (FetchRefused) {
            return null;
        }

        return $this->store($response->body);
    }

    public function fromUpload(UploadedFile $file): ?string
    {
        $bytes = @file_get_contents($file->getRealPath());

        return is_string($bytes) ? $this->store($bytes) : null;
    }

    /**
     * Decode, shrink, encode as WebP, store. Returns the public path
     * (`/media/items/….webp`), or null for anything that is not a picture.
     */
    public function store(string $bytes): ?string
    {
        $info = @getimagesizefromstring($bytes);

        if ($info === false || ! in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP, IMAGETYPE_GIF], true)) {
            return null;
        }

        [$width, $height] = $info;

        if ($width < 1 || $height < 1 || $width * $height > self::MAX_PIXELS) {
            return null;
        }

        $image = @imagecreatefromstring($bytes);

        if (! $image instanceof GdImage) {
            return null;
        }

        $image = $this->oriented($image, $bytes, $info[2]);
        $image = $this->shrunk($image);

        imagepalettetotruecolor($image);
        imagealphablending($image, true);
        imagesavealpha($image, true);

        ob_start();
        imagewebp($image, null, 82);
        $encoded = (string) ob_get_clean();
        imagedestroy($image);

        if ($encoded === '') {
            return null;
        }

        $name = 'items/'.Str::uuid()->toString().'.webp';

        Storage::disk(self::DISK)->put($name, $encoded);

        return '/media/'.$name;
    }

    /** Delete a stored picture by its public path. Anything else is ignored. */
    public function forget(?string $path): void
    {
        if (self::isOurs($path)) {
            Storage::disk(self::DISK)->delete(substr((string) $path, strlen('/media/')));
        }
    }

    public static function isOurs(?string $path): bool
    {
        return is_string($path) && (bool) preg_match('#^/media/items/[0-9a-f-]{36}\.webp$#', $path);
    }

    /**
     * Turn a phone photo the right way up before the EXIF that says which way
     * is up is thrown away with the rest of it.
     */
    private function oriented(GdImage $image, string $bytes, int $type): GdImage
    {
        if ($type !== IMAGETYPE_JPEG || ! function_exists('exif_read_data')) {
            return $image;
        }

        $exif = @exif_read_data('data://image/jpeg;base64,'.base64_encode($bytes));
        $rotated = match ((int) ($exif['Orientation'] ?? 1)) {
            3 => imagerotate($image, 180, 0),
            6 => imagerotate($image, -90, 0),
            8 => imagerotate($image, 90, 0),
            default => $image,
        };

        return $rotated instanceof GdImage ? $rotated : $image;
    }

    private function shrunk(GdImage $image): GdImage
    {
        $width = imagesx($image);
        $height = imagesy($image);
        $longest = max($width, $height);

        if ($longest <= self::MAX_SIDE) {
            return $image;
        }

        $scale = self::MAX_SIDE / $longest;
        $resized = imagescale($image, max(1, (int) round($width * $scale)), max(1, (int) round($height * $scale)));

        return $resized instanceof GdImage ? $resized : $image;
    }
}
