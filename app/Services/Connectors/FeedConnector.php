<?php

declare(strict_types=1);

namespace App\Services\Connectors;

use App\Enums\Market;
use App\Models\Feed;
use Generator;

/**
 * A source we ingest into our own index on a schedule.
 *
 * The contract is a generator rather than an array on purpose: a product feed
 * runs to hundreds of megabytes and tens of thousands of rows, and loading one
 * into memory is how a worker gets OOM-killed halfway through.
 */
interface FeedConnector extends SourceConnector
{
    /**
     * Stream normalised offers from a feed, resuming from $cursor.
     *
     * Implementations must yield in a stable order so a resumed run picks up
     * where it left off rather than re-processing from the top.
     *
     * @param  array<string, mixed>|null  $cursor  Opaque resume point from a previous run.
     * @return Generator<int, Offer>
     */
    public function stream(Feed $feed, ?array $cursor = null): Generator;

    /**
     * Where the last yielded row leaves the cursor.
     *
     * Called after each chunk is persisted, so the recorded position always
     * trails committed work — never leads it. Leading would silently skip rows
     * if the process died between recording and committing.
     *
     * @return array<string, mixed>
     */
    public function cursor(): array;

    /** Total rows, when the source can tell us cheaply. Drives progress display. */
    public function total(): ?int;

    /** Feeds this connector can ingest for a market, for the admin picker. */
    public function availableFeeds(Market $market): array;
}
