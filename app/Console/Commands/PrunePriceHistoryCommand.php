<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Trim price history to the retention window.
 *
 * The table exists to serve a 30-day median and a 90-day sparkline. Without
 * pruning it grows without bound: one row per priced product per day is roughly
 * 20 million rows a year on a 60k catalogue, all of it to answer questions
 * about the last three months.
 */
class PrunePriceHistoryCommand extends Command
{
    protected $signature = 'bc:prune-price-history {--days=90 : Retention window}';

    protected $description = 'Delete price history older than the retention window';

    public function handle(): int
    {
        $days = (int) $this->option('days');
        $cutoff = now()->subDays($days)->toDateString();

        // Deleted in batches so a long-running statement never holds a lock
        // across the whole table while ingestion is trying to write to it.
        $total = 0;
        do {
            $deleted = DB::table('price_history')
                ->whereIn('id', fn ($q) => $q
                    ->select('id')
                    ->from('price_history')
                    ->where('captured_on', '<', $cutoff)
                    ->limit(10_000)
                )
                ->delete();

            $total += $deleted;
        } while ($deleted > 0);

        $this->info("Pruned {$total} price history rows older than {$days} days.");

        return self::SUCCESS;
    }
}
