<?php

declare(strict_types=1);

namespace App\Services\Connectors;

use App\Enums\Market;

/**
 * A source that publishes what people are actually buying.
 *
 * A third capability alongside {@see FeedConnector} and {@see LiveConnector},
 * rather than methods on either, because the capabilities are independent: Awin
 * is a feed with no chart at all, and Amazon will have a chart under storage
 * rules that forbid mirroring the products in it. A source declares what it can
 * do by which interfaces it implements.
 *
 * What comes back is a *ranking*, and the rank is the payload. It is stored as a
 * decision — external id, position, date — never as a copy of the source's
 * catalogue, which is what makes the same table usable for Amazon later. See
 * docs/features/popularity-charts.md.
 *
 * Degrades rather than throws, on the same reasoning as LiveConnector: this runs
 * on a schedule against a rate-limited API, and a bad afternoon at the upstream
 * must cost one day's snapshot, not the job.
 */
interface PopularityConnector extends SourceConnector
{
    /**
     * One chart: the most popular products, rank 1 first.
     *
     * @param  string|null  $categoryId  null for the market-wide chart
     * @param  int  $limit  how many entries to ask for, across pages
     */
    public function popular(Market $market, ?string $categoryId, int $limit): PopularChart;

    /** Whether the source is currently backing off after a 429. */
    public function isChartCoolingDown(): bool;
}
