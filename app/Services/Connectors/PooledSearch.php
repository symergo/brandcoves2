<?php

declare(strict_types=1);

namespace App\Services\Connectors;

use App\Enums\Market;

/**
 * A live connector whose search can be sent alongside the others'.
 *
 * For the one place a visitor waits on the shops: a search whose stored
 * results are thinner than a page (SearchService::askNow()). There the
 * connectors are asked in parallel with a short timeout and no retry, which
 * `search()` cannot do because it sends its own request and waits for it.
 *
 * Separate from LiveConnector so a connector without it (a test stand-in,
 * Amazon when it arrives) is still asked, one after the other, through
 * `search()`.
 */
interface PooledSearch
{
    /**
     * The offers straight away when the connector's own cache has them, an
     * empty list when there is nothing to ask (unsupported market, rate
     * limited, no credentials), or the request to send.
     *
     * @param  int  $timeout  seconds, for anything this has to fetch first (a token)
     * @return list<Offer>|LiveRequest
     */
    public function searchRequest(string $query, Market $market, int $limit = 24, int $timeout = 3): array|LiveRequest;
}
