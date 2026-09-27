<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\Market;
use App\Services\Ingestion\ProductGrouper;
use App\Services\Search\SearchGenerations;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\Queue;
use Illuminate\Support\Facades\Log;

/**
 * Collapse offers into physical products for one market.
 *
 * Runs after ingestion rather than inside it: grouping is set-based SQL over
 * the whole market, so doing it once at the end is both faster and more correct
 * than doing it per chunk, where a group's "cheapest offer" would be computed
 * from a catalogue that is still half-loaded.
 */
#[Queue('batch')]
class GroupProducts implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $timeout = 900;

    // One grouping per market at a time. `uniqueId()` below only counts on a
    // job that implements ShouldBeUnique — see the note on IngestFeed.
    public int $uniqueFor = 900;

    public function __construct(
        public readonly Market $market,
    ) {}

    public function uniqueId(): string
    {
        return 'group-products-'.$this->market->value;
    }

    public function handle(ProductGrouper $grouper): void
    {
        $result = $grouper->run($this->market);

        // The catalogue under every cached search of this market just
        // changed: retire them all (search results and facets are kept 12
        // hours between these moments). See SearchGenerations.
        SearchGenerations::bumpMarket($this->market);

        Log::info('Products grouped', [
            'market' => $this->market->value,
            ...$result,
        ]);
    }
}
