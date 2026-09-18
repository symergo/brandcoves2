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
     * Re-check one offer we already hold, by whatever identifies it HERE.
     *
     * A caller holding a `products` row knows two things about it: the
     * `external_id` this source gave us, and the barcode when the row has one.
     * Which of the two the source can actually answer for is a fact about the
     * source, so it is answered here rather than by the caller.
     *
     * bol is why this method exists. Its product endpoint is keyed on the EAN
     * and answers 400 for a `bolProductId` — which is exactly what we store in
     * `external_id` — so a refresh that passed the id asked for something that
     * could never succeed, and no bol offer was ever refreshed. eBay's and
     * Tradedoubler's ids are their own, so for them this is `fetchById()`.
     *
     * Null means "nothing here can be asked with" or "the source did not
     * answer". It never means the product is gone, so a caller must leave the
     * stored row exactly as it was.
     */
    public function refresh(string $externalId, ?string $ean, Market $market): ?Offer;

    /**
     * Whether the source is currently backing off after a 429.
     *
     * Callers check this to skip the source entirely rather than queueing
     * requests behind a limit that is already refusing them.
     */
    public function isCoolingDown(): bool;
}
