<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Trim the rank time series to its retention window.
 *
 * This was `bc:prune-price-history` and pruned two tables. `price_history` is
 * gone (2026-09-12): an offer keeps its first, previous and current price on
 * its own row and nothing samples prices daily any more, so there is nothing
 * of that kind to trim. Rank history stays: a few hundred rows a day, kept for
 * a long window because the popularity charts compare against last year.
 */
class PruneRankHistoryCommand extends Command
{
    protected $signature = 'bc:prune-rank-history
        {--days= : Rank history retention window; defaults to the configured one}';

    protected $description = 'Delete rank history older than its retention window';

    public function handle(): int
    {
        $days = (int) ($this->option('days')
            ?? config('giftcoves.connectors.bol.popular.history_days', 400));

        $ranks = $this->prune('popular_ranks', now()->subDays($days)->toDateString());

        $this->info("Pruned {$ranks} rank history rows older than {$days} days.");

        return self::SUCCESS;
    }

    /**
     * Delete in slices, so a large backlog never holds one long lock.
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
