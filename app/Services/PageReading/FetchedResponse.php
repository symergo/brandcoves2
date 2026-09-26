<?php

declare(strict_types=1);

namespace App\Services\PageReading;

/** What a fetch brought back, and the URL it finally came from after redirects. */
final readonly class FetchedResponse
{
    public function __construct(
        public string $url,
        public string $contentType,
        public string $body,
    ) {}
}
