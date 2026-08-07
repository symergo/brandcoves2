<?php

declare(strict_types=1);

namespace App\Services\Connectors;

use App\Enums\Market;

/**
 * A source queried in real time rather than ingested.
 *
 * Results are cached and rate-limited. Implementations must degrade rather than
 * throw: if the upstream is down, rate-limited or slow, search has to keep
 * working with whatever the other sources returned. A dead live source is a
 * smaller result set, never a broken page.
 */
interface LiveConnector extends SourceConnector
{
    /**
     * @return list<Offer> Empty when the source is unavailable — never an exception.
     */
    public function search(string $query, Market $market, int $limit = 24): array;

    /** Refresh a single known item, for wishlist and daily-pick re-checks. */
    public function fetchById(string $externalId, Market $market): ?Offer;

    /**
     * Whether the source is currently backing off after a 429.
     *
     * Callers check this to skip the source entirely rather than queueing
     * requests behind a limit that is already refusing them.
     */
    public function isCoolingDown(): bool;
}
