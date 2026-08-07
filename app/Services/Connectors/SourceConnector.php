<?php

declare(strict_types=1);

namespace App\Services\Connectors;

use App\Enums\Market;
use App\Enums\Source;

/**
 * Every product source implements this.
 *
 * Two modes, because the two kinds of source fail differently:
 *
 *   FEED sources (Awin) are downloaded on a schedule into our own index. They
 *   are large, slow and eventually consistent, and must be chunked and
 *   resumable — a feed runs to hundreds of megabytes and a redeploy mid-run
 *   must not lose the work.
 *
 *   LIVE sources (bol, Amazon) are queried per request and cached. They are
 *   small, fast, rate-limited, and in Amazon's case may not be mirrored at all.
 *
 * Adding a source is a new implementation plus a config entry — never a change
 * to the ingestion or search code.
 */
interface SourceConnector
{
    public function source(): Source;

    /** Whether this connector is configured and enabled for a market. */
    public function supports(Market $market): bool;
}
