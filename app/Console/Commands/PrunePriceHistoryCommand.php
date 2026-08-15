<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Trim the time series to their retention windows.
 *
 * Price history exists to serve a 30-day median and a 90-day sparkline. Without
 * pruning it grows without bound: one row per priced product per day is roughly
 * 20 million rows a year on a 60k catalogue, all of it to answer questions
 * about the last three months.
 *
 * Rank history is the same shape and a very different size — a few hundred rows
 * a day rather than tens of thousands — so it keeps a far longer window. Both
 * live here because they are one operational concern: append-only daily samples
 * that nothing else ever deletes.
 */
class PrunePriceHistoryCommand extends Command
{
    protected $signature = 'bc:prune-price-history
        {--days=90 : Price history retention window}
        {--rank-days= : Rank history retention window; defaults to the configured one}';

    protected $description = 'Delete price and rank history older than their retention windows';

    public function handle(): int
    {
        $days = (int) $this->option('days');

        $prices = $this->prune('price_history', now()->subDays($days)->toDateString());
        $this->info("Pruned {$prices} price history rows older than {$days} days.");

        /*
         * Long by design — the default is 400 days.
         *
         * A year plus a margin is what makes "was this climbing last August
         * too?" answerable, and seasonality is most of what a bestseller chart
         * has to say in a gifting catalogue. The table is small enough that the
         * extra year costs nothing worth counting.
         */
        $rankDays = (int) ($this->option('rank-days')
            ?? config('giftcoves.connectors.bol.popular.history_days', 400));

        $ranks = $this->prune('popular_ranks', now()->subDays($rankDays)->toDateString());
        $this->info("Pruned {$ranks} rank history rows older than {$rankDays} days.");

        return self::SUCCESS;
    }

    /**
     * Deleted in batches so a long-running statement never holds a lock across
     * the whole table while ingestion is trying to write to it.
     */
    private function prune(string $table, string $cutoff): int
    {
        $total = 0;

        do {
            $deleted = DB::table($table)
                ->whereIn('id', fn ($q) => $q
                    ->select('id')
                    ->from($table)
                    ->where('captured_on', '<', $cutoff)
                    ->limit(10_000)
                )
                ->delete();

            $total += $deleted;
        } while ($deleted > 0);

        return $total;
    }
}
