<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\Search\SearchQuery;
use App\Services\Search\SearchService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\Queue;

/**
 * Recompute a search's cached result ids and filter counts, after a visitor
 * was shown the outdated ones (stale while revalidate, owner 2026-10-25).
 *
 * Queued rather than run after the response in the same PHP worker: after
 * the twice-daily catalogue update every visited term is outdated at once,
 * and the queue works them off one at a time instead of as many parallel
 * searches as there are visitors. Unique per result slot, so a burst of
 * visitors to one outdated term is one recomputation. See
 * SearchService::staleWhileRevalidate().
 */
#[Queue('default')]
class RefreshSearchCache implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /** One try: the next visitor to the still-outdated term queues it again. */
    public int $tries = 1;

    /** A search with the fuzzy match took up to ~5 s on production; generous. */
    public int $timeout = 60;

    /** Held until the job ends; this only matters if a worker dies holding it. */
    public int $uniqueFor = 300;

    public function __construct(public readonly SearchQuery $query) {}

    public function uniqueId(): string
    {
        return $this->query->resultsCacheSlot();
    }

    public function handle(SearchService $search): void
    {
        $search->refreshCache($this->query);
    }
}
