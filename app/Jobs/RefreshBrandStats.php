<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\Market;
use App\Services\Catalogue\BrandStats;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Recompute one market's brand statistics.
 *
 * Brand pages are entirely made of these numbers, and every sentence on them
 * asserts one. So this runs after grouping — grouping is what produces the
 * cheapest price, the median and the merchant count the copy quotes — and the
 * page itself never aggregates anything.
 */
class RefreshBrandStats implements ShouldQueue
{
    use Queueable;

    public int $timeout = 900;

    public function __construct(public Market $market) {}

    public function handle(BrandStats $stats): void
    {
        $count = $stats->refresh($this->market);

        Log::info('Brand stats refreshed', [
            'market' => $this->market->value,
            'brands' => $count,
        ]);
    }
}
