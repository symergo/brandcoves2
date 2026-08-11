<?php

declare(strict_types=1);

namespace App\Services\Connectors;

/**
 * One position on a chart.
 *
 * The offer is the *product*; the rank is the thing this whole feature exists
 * for. They are kept as separate fields rather than a rank property on Offer
 * because an Offer has no rank in any other context — it arrives from a feed or
 * a search with no position at all, and a nullable rank on the canonical shape
 * would be null almost everywhere.
 */
final readonly class ChartEntry
{
    public function __construct(
        public Offer $offer,
        /** 1-based. Rank 1 is the top of the chart. */
        public int $rank,
    ) {}
}
