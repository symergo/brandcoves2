<?php

declare(strict_types=1);

namespace App\Services\Images;

use App\Services\PageReading\FetchRefused;
use App\Services\PageReading\SafeFetch;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * The resized copies behind `/img/...`: fetched once, kept on disk.
 *
 * They live on the `media` disk, under `proxy/`, because that disk is the
 * persistent volume in production (`media_data`): the container's own disk is
 * wiped by every deploy, and a cache that empties at each deploy makes the
 * first visitor after it wait for every picture again. `items/` beside it
 * holds people's own photos and is never touched from here.
 *
 * A copy is keyed by the source URL and the width, not by the picture's
 * content: a shop that replaces the picture behind the same URL is shown the
 * old one until `bc:prune-image-cache` removes copies older than
 * `giftcoves.image_proxy.keep_days`. Feeds give a changed picture a new URL
 * far more often than not, so that is the cheap trade.
 */
class ProxiedImages
{
    private const DIRECTORY = 'proxy';

    /** How long a failed fetch is remembered, so a dead URL is not asked for on every page view. */
    private const FAILURE_SECONDS = 3600;

    public function __construct(
        private readonly SafeFetch $fetch,
        private readonly ImageStore $encoder,
    ) {}

    /** The disk path of a stored copy (relative to the disk), whether or not it exists yet. */
    public static function path(string $url, int $width): string
    {
        $hash = sha1($url);

        return self::DIRECTORY.'/'.$width.'/'.substr($hash, 0, 2).'/'.$hash.'.webp';
    }

    /** The absolute file path of the copy, when there is one. */
    public function cached(string $url, int $width): ?string
    {
        $disk = Storage::disk(ImageStore::DISK);
        $path = self::path($url, $width);

        return $disk->exists($path) ? $disk->path($path) : null;
    }

    /** Whether this URL failed recently; the caller sends the browser to the original instead. */
    public function recentlyFailed(string $url): bool
    {
        return Cache::has($this->failureKey($url));
    }

    /**
     * Fetch, resize, encode, store. The absolute file path, or null when the
     * picture could not be had (the failure is remembered for an hour).
     */
    public function make(string $url, int $width): ?string
    {
        $bytes = null;

        foreach (SourceVariants::for($url, $width) as $source) {
            $bytes = $this->download($source);

            if ($bytes !== null) {
                break;
            }
        }

        // Twice the width on the long side: a panorama in a square slot still
        // fills it, and nothing grows past what a 2x screen can show.
        $encoded = $bytes === null ? null : $this->encoder->webp(
            $bytes,
            maxSide: $width * 2,
            quality: (int) config('giftcoves.image_proxy.quality', 80),
            coverSide: $width,
        );

        if ($encoded === null) {
            Cache::put($this->failureKey($url), true, self::FAILURE_SECONDS);

            return null;
        }

        $disk = Storage::disk(ImageStore::DISK);
        $path = self::path($url, $width);

        // Written under a temporary name and renamed: two visitors asking for
        // the same new picture at once must never serve each other half a file.
        $temporary = $path.'.'.bin2hex(random_bytes(4)).'.tmp';
        $disk->put($temporary, $encoded);

        if (! @rename($disk->path($temporary), $disk->path($path))) {
            $disk->delete($temporary);

            return $disk->exists($path) ? $disk->path($path) : null;
        }

        return $disk->path($path);
    }

    private function download(string $url): ?string
    {
        try {
            $response = $this->fetch->get(
                $url,
                ['image/jpeg', 'image/png', 'image/webp', 'image/gif'],
                (int) config('giftcoves.image_proxy.max_bytes', 5 * 1024 * 1024),
                (int) config('giftcoves.image_proxy.timeout', 4),
            );
        } catch (FetchRefused $e) {
            Log::debug('image proxy: fetch refused', ['url' => $url, 'reason' => $e->getMessage()]);

            return null;
        }

        // A redirect may have led somewhere the first URL was not. Amazon is
        // refused wherever the chain ends (invariant 6).
        $host = (string) parse_url($response->url, PHP_URL_HOST);

        if (ImageProxy::isAmazon($host)) {
            return null;
        }

        return $response->body;
    }

    private function failureKey(string $url): string
    {
        return 'image-proxy:failed:'.sha1($url);
    }
}
