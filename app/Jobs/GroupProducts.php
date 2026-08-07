<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\Market;
use App\Services\Ingestion\ProductGrouper;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Collapse offers into physical products for one market.
 *
 * Runs after ingestion rather than inside it: grouping is set-based SQL over
 * the whole market, so doing it once at the end is both faster and more correct
 * than doing it per chunk, where a group's "cheapest offer" would be computed
 * from a catalogue that is still half-loaded.
 */
class GroupProducts implements ShouldQueue
{
    use Queueable;

    public int $timeout = 900;

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

        Log::info('Products grouped', [
            'market' => $this->market->value,
            ...$result,
        ]);
    }
}
