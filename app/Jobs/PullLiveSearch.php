<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\Market;
use App\Services\Search\SearchGenerations;
use App\Services\Search\SearchQuery;
use App\Services\Search\SearchService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Ask the live shops (bol, eBay, Tradedoubler) about a search, and store what
 * they answer.
 *
 * Queued since 2026-09-27. Until then a search or brand page that missed the
 * live marker called every live connector one after the other, each with an
 * 8-second timeout and two retries, then wrote and grouped the offers, all
 * before the page rendered. A term nobody had typed in the last fifteen
 * minutes cost the visitor that wait, and a crawler walking the brand pages
 * paid it on every brand. Now the page renders from what we already hold and
 * this job fetches in the background; the new offers are on the next view.
 *
 * Only shops whose offers may be stored come here. A source that must be
 * fetched at render (Amazon, invariant 6) is still asked inside the request,
 * because there is nothing durable a job could leave behind for it.
 *
 * Unique on the same key the request throttles with (`liveCacheKey()`), so a
 * burst of identical searches before the marker lands is still one fetch.
 */
class PullLiveSearch implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /**
     * One try. The connectors degrade rather than throw, and a retry is more
     * requests to a shop that just refused; the next search after the marker
     * expires asks again anyway.
     */
    public int $tries = 1;

    /** Three connectors at 8 s each with two retries, and the write after. */
    public int $timeout = 90;

    /** Held until the job ends; this only matters if a worker dies holding it. */
    public int $uniqueFor = 300;

    /** @param  list<string>  $brands  a brand page's spellings, for attribution */
    public function __construct(
        public readonly Market $market,
        public readonly string $liveTerm,
        public readonly array $brands = [],
    ) {}

    public function uniqueId(): string
    {
        return $this->query()->liveCacheKey();
    }

    public function handle(SearchService $search): void
    {
        try {
            $search->foldLive($this->query());
        } finally {
            /*
             * Retire every cached result of this term, every filter and sort
             * of it, and of this brand's page and its sub-searches, whether
             * the shops answered anything or not. The results cached while the
             * job was queued lack exactly what it just stored; the next view
             * reads afresh and has it. Also on failure: a half-finished fold
             * may still have written offers.
             */
            SearchGenerations::bumpTerm($this->market, $this->liveTerm);
            SearchGenerations::bumpBrands($this->market, $this->brands);
        }
    }

    private function query(): SearchQuery
    {
        return (new SearchQuery(market: $this->market, liveTerm: $this->liveTerm))
            ->withBrands($this->brands);
    }
}
