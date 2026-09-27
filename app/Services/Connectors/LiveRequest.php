<?php

declare(strict_types=1);

namespace App\Services\Connectors;

use Closure;
use Illuminate\Http\Client\Response;

/**
 * One live search, described rather than sent, so the caller can send several
 * at once (PooledSearch).
 *
 * `finish` is the connector's own half: it reads the response (null when the
 * request timed out or never connected), does the same status handling its
 * ordinary search does (a 429 backs off, a 401 drops the token), caches the
 * raw payload and returns offers.
 */
final readonly class LiveRequest
{
    /**
     * @param  array<string, mixed>  $query
     * @param  array<string, string>  $headers
     * @param  Closure(?Response): list<Offer>  $finish
     */
    public function __construct(
        public string $url,
        public array $query,
        public array $headers,
        public ?string $token,
        public Closure $finish,
    ) {}
}
