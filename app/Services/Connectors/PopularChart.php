<?php

declare(strict_types=1);

namespace App\Services\Connectors;

/**
 * One chart, plus whatever the source let slip about its own taxonomy.
 *
 * The categories are not decoration. bol publishes no category-id list, so the
 * relevant-categories block on a chart response is the only way to discover the
 * next chart worth pulling — a chart is simultaneously this run's data and the
 * next run's frontier.
 */
final readonly class PopularChart
{
    /**
     * @param  list<ChartEntry>  $entries  rank order, 1 first
     * @param  list<ChartCategory>  $categories  discovered from this response
     */
    public function __construct(
        public array $entries = [],
        public array $categories = [],
    ) {}

    /** An unavailable source: rate limited, down, or not configured. */
    public static function empty(): self
    {
        return new self;
    }

    public function isEmpty(): bool
    {
        return $this->entries === [];
    }
}
