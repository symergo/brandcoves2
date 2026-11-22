<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Catalogue\DescriptionLimit;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Cut the descriptions stored before 2026-11-22 to DescriptionLimit's 1,000 characters.
 *
 * Ingestion cuts every offer it writes, but it only writes offers that changed, and most
 * of bol's offers are fetched again only when somebody searches for them. So the stored
 * ones are cut here, once, in the database (DescriptionLimit::SQL, the same cut).
 *
 * In ranges of ids along the primary key, so it can stop and resume (`--from`, printed as
 * it goes) and holds no lock for long: each range is one short UPDATE of the rows in it
 * that are still too long, which also makes a second run a no-op. A rewritten row leaves
 * its old version behind until VACUUM marks it reusable, so a plain VACUUM (no lock, not
 * FULL) runs every `--vacuum-every` rewritten rows; otherwise the table would first grow
 * by every row rewritten. Without parallel workers: the server is busy enough, and a
 * container's small default shared memory made a parallel one fail locally. The space
 * freed is reused by the table; it goes back to the disk only with VACUUM FULL, which
 * locks the table (docs/features/speed.md).
 *
 * A dry run unless `--write`: it estimates from a 1% sample.
 */
class TrimDescriptionsCommand extends Command
{
    protected $signature = 'bc:trim-descriptions
        {--write : Cut the descriptions. Without this it only estimates}
        {--from=0 : Start at this id, to resume}
        {--step=500000 : Ids per range}
        {--pause=200 : Milliseconds between ranges}
        {--vacuum-every=300000 : Run VACUUM on products after this many rewritten rows (0: never)}';

    protected $description = 'Cut stored offer descriptions to 1,000 characters (dry run unless --write)';

    public function handle(): int
    {
        $limit = DescriptionLimit::CHARACTERS;

        if (! $this->option('write')) {
            $estimate = DB::selectOne(
                "SELECT count(*) AS sampled, count(*) FILTER (WHERE char_length(description) > {$limit}) AS longer,"
                .' coalesce(sum(octet_length(description) - octet_length('.DescriptionLimit::SQL.')) FILTER (WHERE char_length(description) > '.$limit.'), 0) AS bytes'
                .' FROM products TABLESAMPLE SYSTEM (1)',
            );
            $this->info(sprintf(
                'From a 1%% sample: about %s offers have a description over %d characters; cutting them removes about %s MB of text (the search column shrinks with it).',
                number_format((int) $estimate->longer * 100),
                $limit,
                number_format((int) $estimate->bytes * 100 / 1048576),
            ));
            $this->comment('Dry run. Nothing written; add --write to cut.');

            return self::SUCCESS;
        }

        $step = max(1000, (int) $this->option('step'));
        $pause = max(0, (int) $this->option('pause'));
        $vacuumEvery = max(0, (int) $this->option('vacuum-every'));
        $from = max(0, (int) $this->option('from'));
        $max = (int) DB::table('products')->max('id');

        $cut = 0;
        $sinceVacuum = 0;
        $ranges = 0;

        for ($start = $from; $start <= $max; $start += $step) {
            $end = $start + $step;

            $cut += $changed = DB::update(
                'UPDATE products SET description = '.DescriptionLimit::SQL
                .' WHERE id >= ? AND id < ? AND char_length(description) > '.$limit,
                [$start, $end],
            );
            $sinceVacuum += $changed;
            $ranges++;

            if ($vacuumEvery > 0 && $sinceVacuum >= $vacuumEvery) {
                $this->line("  VACUUM products after {$sinceVacuum} rewritten rows…");
                DB::statement('VACUUM (PARALLEL 0) products');
                $sinceVacuum = 0;
            }

            if ($ranges % 20 === 0) {
                $this->line(sprintf('  up to id %s: %s cut (resume with --from=%d)', number_format($end), number_format($cut), $end));
            }

            if ($pause > 0) {
                usleep($pause * 1000);
            }
        }

        if ($vacuumEvery > 0 && $sinceVacuum > 0) {
            DB::statement('VACUUM (PARALLEL 0) products');
        }

        $this->info(sprintf('Done: %s descriptions cut to %d characters.', number_format($cut), $limit));

        return self::SUCCESS;
    }
}
