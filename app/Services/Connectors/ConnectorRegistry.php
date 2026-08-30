<?php

declare(strict_types=1);

namespace App\Services\Connectors;

use App\Enums\Market;
use App\Enums\Source;
use InvalidArgumentException;

/**
 * The single place that knows which connectors exist.
 *
 * Adding a source is a registration here plus a config entry — never a change
 * to the ingestion pipeline or the search service, both of which only ever see
 * the interfaces.
 */
class ConnectorRegistry
{
    /** @var array<string, FeedConnector> */
    private array $feed = [];

    /** @var array<string, LiveConnector> */
    private array $live = [];

    /** @var array<string, PopularityConnector> */
    private array $popularity = [];

    public function registerFeed(FeedConnector $connector): void
    {
        $this->feed[$connector->source()->value] = $connector;
    }

    public function registerLive(LiveConnector $connector): void
    {
        $this->live[$connector->source()->value] = $connector;
    }

    /**
     * A source that publishes a bestseller chart.
     *
     * Separate from registerLive() because the capabilities are separate: a
     * source may do one, both or neither. bol does both from one class; Awin
     * does neither.
     */
    public function registerPopularity(PopularityConnector $connector): void
    {
        $this->popularity[$connector->source()->value] = $connector;
    }

    public function feed(Source $source): FeedConnector
    {
        return $this->feed[$source->value]
            ?? throw new InvalidArgumentException("No feed connector registered for {$source->value}");
    }

    /**
     * Live connectors usable for a market, in the order search should try them.
     *
     * A connector that is disabled, unconfigured or currently backing off after
     * a 429 is excluded here rather than being called and failing — search
     * degrades to fewer sources instead of waiting on one that is refusing.
     *
     * @return list<LiveConnector>
     */
    public function liveFor(Market $market): array
    {
        return array_values(array_filter(
            $this->live,
            fn (LiveConnector $c) => $c->supports($market) && ! $c->isCoolingDown(),
        ));
    }

    /**
     * Sources that can chart this market, cooling-down ones included.
     *
     * Deliberately unlike liveFor(), which filters out a backing-off connector
     * so a *request* degrades. This one is called by a scheduled job that keeps
     * a cursor and resumes: dropping a source here would silently skip its
     * chart for the day and leave a hole in the rank history that nothing ever
     * fills. The job checks the cooldown itself and stops, keeping its place.
     *
     * @return list<PopularityConnector>
     */
    public function popularityFor(Market $market): array
    {
        return array_values(array_filter(
            $this->popularity,
            fn (PopularityConnector $c) => $c->supports($market),
        ));
    }

    /**
     * Live sources serving a market, cooling-down ones included.
     *
     * Deliberately unlike liveFor(), for the same reason popularityFor() is:
     * that one answers "who can I ask right now" and drops a source backing off
     * after a 429 so a *request* degrades. This one answers "who do we compare
     * in this market", which is a fact about the integration rather than about
     * this second's rate limit. A shop directory that loses bol because bol is
     * briefly refusing would tell a visitor we do not carry it.
     *
     * @return list<Source>
     */
    public function liveSourcesFor(Market $market): array
    {
        return array_values(array_map(
            fn (LiveConnector $c) => $c->source(),
            array_filter($this->live, fn (LiveConnector $c) => $c->supports($market)),
        ));
    }

    /** @return list<Source> */
    public function feedSources(): array
    {
        return array_map(fn (string $s) => Source::from($s), array_keys($this->feed));
    }

    /**
     * Every live source this build knows about, market or no market.
     *
     * The denominator liveFor() and liveSourcesFor() are numerators of. Both of
     * those answer "who serves this market", and neither can distinguish a
     * source that is registered but unconfigured from one that was never
     * integrated at all — the two look identical from the outside and need
     * completely different work to fix. A diagnostic has to tell them apart.
     *
     * @return list<Source>
     */
    public function liveSources(): array
    {
        return array_map(fn (string $s) => Source::from($s), array_keys($this->live));
    }

    /**
     * One live connector, or null when the source has none.
     *
     * Deliberately nullable where feed() throws. feed() is called by the
     * ingestion pipeline, which has already decided the source exists and
     * cannot proceed if it does not — an exception is the honest answer there.
     * This one is called by a diagnostic asking "is this source integrated",
     * and a question whose whole purpose is to report "no" must be able to
     * return it rather than blowing up the page that asked.
     */
    public function live(Source $source): ?LiveConnector
    {
        return $this->live[$source->value] ?? null;
    }
}
