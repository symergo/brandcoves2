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

    public function registerFeed(FeedConnector $connector): void
    {
        $this->feed[$connector->source()->value] = $connector;
    }

    public function registerLive(LiveConnector $connector): void
    {
        $this->live[$connector->source()->value] = $connector;
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

    /** @return list<Source> */
    public function feedSources(): array
    {
        return array_map(fn (string $s) => Source::from($s), array_keys($this->feed));
    }
}
