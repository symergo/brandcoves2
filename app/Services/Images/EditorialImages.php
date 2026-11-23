<?php

declare(strict_types=1);

namespace App\Services\Images;

use App\Services\PageReading\FetchRefused;
use App\Services\PageReading\SafeFetch;
use Illuminate\Support\Facades\Storage;

/**
 * Pictures for articles, kept on our own server (owner, 2026-11-23).
 *
 * An article's `[[image:URL|…]]` used to point at somebody else's server: a
 * shop's campaign banner, Wikimedia's copy of a logo. Those move, vanish after
 * the campaign, or throttle us (Wikimedia answered 429 the day the Prime
 * articles went out), and the article then shows a hole. So the editorial API
 * takes a file or an address, and this stores a WebP copy under
 * `media/editorial/`, which the article then names by our own address.
 *
 * Its own folder, not `items/` beside it: `bc:prune-personal-data` deletes every
 * picture in `items/` that no list item refers to, which every article picture
 * is. Nothing prunes `editorial/`; an article picture lives as long as the page.
 *
 * The file is named after its content (sha1 of the WebP), so sending the same
 * picture twice, or retrying after a timeout, gives the same address and stores
 * one file.
 *
 * The encoding is ImageStore's: JPEG, PNG, WebP or GIF only (never SVG, which is
 * code), at most 40 megapixels, shrunk to 1600 px on the long side, flattened
 * onto white, re-encoded. Re-encoding is what makes it safe to serve: whatever
 * came in, what goes out is a picture we drew.
 *
 * Never Amazon (invariant 6): a picture on an Amazon host, or one an address
 * redirects to, is refused rather than copied.
 */
final class EditorialImages
{
    public const FOLDER = 'editorial';

    private const MAX_SIDE = 1600;

    /** A touch higher than list items' 82: an article picture is shown larger. */
    private const QUALITY = 85;

    private const MAX_BYTES = 10 * 1024 * 1024;

    /** Seconds. A press-kit picture can be large and slow; this runs in an API call, not a page view. */
    private const TIMEOUT = 15;

    private const TYPES = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];

    public function __construct(
        private readonly SafeFetch $fetch,
        private readonly ImageStore $encoder,
    ) {}

    /**
     * Fetch a picture from an address and keep our copy.
     *
     * @return array{path: string, width: int, height: int}|string the stored picture, or why not
     */
    public function fromUrl(string $url): array|string
    {
        if (ImageProxy::isAmazon((string) parse_url($url, PHP_URL_HOST))) {
            return 'Amazon pictures are never copied to this site.';
        }

        try {
            $response = $this->fetch->get($url, self::TYPES, self::MAX_BYTES, self::TIMEOUT);
        } catch (FetchRefused $e) {
            return 'The picture could not be fetched ('.$e->getMessage().').';
        }

        if (ImageProxy::isAmazon((string) parse_url($response->url, PHP_URL_HOST))) {
            return 'Amazon pictures are never copied to this site.';
        }

        return $this->store($response->body);
    }

    /**
     * Keep our copy of an uploaded picture.
     *
     * @return array{path: string, width: int, height: int}|string the stored picture, or why not
     */
    public function fromBytes(string $bytes): array|string
    {
        return $this->store($bytes);
    }

    /** @return array{path: string, width: int, height: int}|string */
    private function store(string $bytes): array|string
    {
        if (strlen($bytes) > self::MAX_BYTES) {
            return 'The picture is larger than 10 MB.';
        }

        $encoded = $this->encoder->webp($bytes, self::MAX_SIDE, self::QUALITY);

        if ($encoded === null) {
            return 'Not a JPEG, PNG, WebP or GIF picture, or too large to decode.';
        }

        $name = self::FOLDER.'/'.sha1($encoded).'.webp';
        $disk = Storage::disk(ImageStore::DISK);

        if (! $disk->exists($name)) {
            $disk->put($name, $encoded);
        }

        $size = getimagesizefromstring($encoded);

        return [
            'path' => '/media/'.$name,
            'width' => $size === false ? 0 : (int) $size[0],
            'height' => $size === false ? 0 : (int) $size[1],
        ];
    }
}
