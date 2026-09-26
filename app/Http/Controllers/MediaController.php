<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\Images\ImageStore;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Serve a picture from the `media` disk.
 *
 * A route rather than a `public/storage` symlink, because the disk is a Docker
 * volume mounted into the container at runtime: a link baked into the image
 * would point at whatever was there at build time. The route also fixes what
 * the answer is: always `image/webp`, whatever bytes are on disk, so nothing
 * stored there can be served as HTML.
 */
class MediaController extends Controller
{
    public function __invoke(string $file): BinaryFileResponse
    {
        $disk = Storage::disk(ImageStore::DISK);
        $path = 'items/'.$file;

        if (! $disk->exists($path)) {
            throw new NotFoundHttpException;
        }

        return response()->file($disk->path($path), [
            'Content-Type' => 'image/webp',
            'X-Content-Type-Options' => 'nosniff',
            // The name is a random UUID and a file is never rewritten in place:
            // a new photo gets a new name. Safe to keep for a year.
            'Cache-Control' => 'public, max-age=31536000, immutable',
        ]);
    }
}
