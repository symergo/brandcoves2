<?php

declare(strict_types=1);

namespace App\Services\Social\Instagram;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use RuntimeException;

/**
 * Publishes one picture with a caption to GiftCoves' Instagram account,
 * through the Instagram Graph API's two-step content publishing:
 *
 *     POST /{ig-user-id}/media          image_url, caption  -> a container id
 *     GET  /{container-id}?fields=status_code               -> FINISHED
 *     POST /{ig-user-id}/media_publish  creation_id         -> the media id
 *
 * Instagram fetches `image_url` itself, so it must be a public JPEG (the API
 * accepts no other format) with an aspect ratio between 4:5 and 1.91:1. Our
 * social cards are 1200x630, inside that range; see the `.jpg` card routes.
 *
 * The token travels as a bearer header and never in a URL: a failed request's
 * exception message carries the URL, and a URL with a token in it is a secret
 * in a log line.
 */
final class InstagramClient
{
    /** How often to ask whether Instagram has fetched the picture, and how long between. */
    private const POLLS = 10;

    private const POLL_SECONDS = 3;

    public function configured(): bool
    {
        return (bool) config('giftcoves.instagram.enabled')
            && filled(config('giftcoves.instagram.access_token'))
            && filled(config('giftcoves.instagram.user_id'));
    }

    /**
     * Post the picture and return the Instagram media id.
     *
     * @throws RuntimeException with Instagram's own error message when a step fails
     */
    public function publish(string $imageUrl, string $caption): string
    {
        $user = (string) config('giftcoves.instagram.user_id');

        $container = $this->id($this->http()->asForm()->post("{$user}/media", [
            'image_url' => $imageUrl,
            'caption' => $caption,
        ]), 'create the post');

        $this->waitUntilReady($container);

        return $this->id($this->http()->asForm()->post("{$user}/media_publish", [
            'creation_id' => $container,
        ]), 'publish the post');
    }

    /**
     * A picture container is usually ready at once, but Instagram fetches the
     * image asynchronously, and publishing before it is FINISHED is an error.
     */
    private function waitUntilReady(string $container): void
    {
        for ($i = 0; $i < self::POLLS; $i++) {
            $status = (string) $this->http()->get($container, ['fields' => 'status_code'])->json('status_code', '');

            if ($status === 'FINISHED') {
                return;
            }

            if ($status === 'ERROR' || $status === 'EXPIRED') {
                throw new RuntimeException("Instagram could not use the picture (container status {$status}).");
            }

            Sleep::for(self::POLL_SECONDS)->seconds();
        }

        throw new RuntimeException('Instagram had not fetched the picture after '.(self::POLLS * self::POLL_SECONDS).' seconds.');
    }

    private function id(Response $response, string $step): string
    {
        $id = (string) $response->json('id', '');

        if ($response->failed() || $id === '') {
            $message = (string) ($response->json('error.message') ?? 'HTTP '.$response->status());

            throw new RuntimeException("Instagram refused to {$step}: {$message}");
        }

        return $id;
    }

    private function http(): PendingRequest
    {
        $host = trim((string) config('giftcoves.instagram.graph_host'), '/');
        $version = (string) config('giftcoves.instagram.graph_version');

        return Http::baseUrl("https://{$host}/{$version}/")
            ->withToken((string) config('giftcoves.instagram.access_token'))
            ->acceptJson()
            ->timeout(30);
    }
}
