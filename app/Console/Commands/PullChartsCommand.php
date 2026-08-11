<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\Market;
use App\Enums\Source;
use App\Jobs\PullPopularCharts;
use App\Models\ChartCategory;
use App\Models\IngestionJob;
use App\Services\Charts\ChartPuller;
use App\Services\Connectors\ConnectorRegistry;
use Illuminate\Console\Command;

/**
 * Pull bestseller charts by hand.
 *
 * The scheduler does this daily. This exists for the first run against a new
 * environment — where every discovery surface is empty until one happens — and
 * for `--discover`, which is the cheapest way to find out whether a source's
 * chart endpoint answers the way we think it does.
 */
class PullChartsCommand extends Command
{
    protected $signature = 'bc:pull-charts
        {--market= : Limit to one market (be-nl, be-fr, en, nl-nl)}
        {--source=bol : Which source to pull}
        {--discover : Print the categories the market-wide chart names, and write nothing}
        {--sync : Run inline instead of queueing, so you can watch it}
        {--fresh : Discard any saved cursor and crawl from the top}';

    protected $description = 'Pull bestseller charts into the demand signal';

    public function handle(ConnectorRegistry $registry, ChartPuller $puller): int
    {
        $source = Source::tryFrom((string) $this->option('source'));

        if ($source === null) {
            $this->error('Unknown source "'.$this->option('source').'". Known: '.implode(', ', Source::values()));

            return self::FAILURE;
        }

        $markets = $this->markets($registry, $source);

        if ($markets === null) {
            return self::FAILURE;
        }

        if ($markets === []) {
            $this->warn('No market in this selection has a chart for '.$source->label().'.');

            return self::SUCCESS;
        }

        if ($this->option('discover')) {
            return $this->discover($registry, $source, $markets);
        }

        if ($this->option('fresh')) {
            IngestionJob::query()
                ->whereIn('job_key', array_map(
                    fn (Market $m) => "{$source->value}:charts:{$m->value}",
                    $markets,
                ))
                ->update(['cursor' => null, 'processed' => 0]);

            ChartCategory::query()
                ->where('source', $source->value)
                ->whereIn('market', array_map(fn (Market $m) => $m->value, $markets))
                // Not deleted: an operator's `enabled = false` is a decision and
                // must survive a re-crawl. Only the "pulled today" mark clears.
                ->update(['last_pulled_at' => null]);

            $this->line('Cursors and pull marks cleared.');
        }

        foreach ($markets as $market) {
            $this->line("→ {$source->label()} charts for {$market->value}");

            $this->option('sync')
                ? PullPopularCharts::dispatchSync($market, $source)
                : PullPopularCharts::dispatch($market, $source);
        }

        $this->info($this->option('sync')
            ? 'Charts pulled.'
            : 'Queued '.count($markets).' market(s). Watch progress in the admin panel.');

        return self::SUCCESS;
    }

    /**
     * Ask for one chart and print what it says about the taxonomy.
     *
     * Writes nothing on purpose. This is the first thing to run against a new
     * environment or after a change to the connector: it proves the credentials,
     * the endpoint and — the part that fails silently otherwise — the response
     * envelope, in one request per market.
     *
     * @param  list<Market>  $markets
     */
    private function discover(ConnectorRegistry $registry, Source $source, array $markets): int
    {
        foreach ($markets as $market) {
            $connector = null;

            foreach ($registry->popularityFor($market) as $candidate) {
                if ($candidate->source() === $source) {
                    $connector = $candidate;
                }
            }

            if ($connector === null) {
                $this->warn("{$market->value}: {$source->label()} publishes no chart here.");

                continue;
            }

            $chart = $connector->popular($market, null, 1);

            $this->newLine();
            $this->line("<info>{$market->value}</info> — ".count($chart->entries).' entr(y|ies), '
                .count($chart->categories).' categor(y|ies)');

            if ($chart->entries === [] && $chart->categories === []) {
                // The single most likely cause, and the one that looks identical
                // to an empty chart. Say so rather than leaving a blank line.
                $this->warn('  Nothing came back. Check the log for an unrecognised envelope,'
                    .' and run bc:check-bol to prove the credentials.');

                continue;
            }

            foreach ($chart->entries as $entry) {
                $this->line("  #{$entry->rank}  {$entry->offer->title}");
            }

            foreach ($chart->categories as $category) {
                $this->line(sprintf(
                    '  %-14s %s%s',
                    $category->externalId,
                    $category->name,
                    $category->productCount === null ? '' : " ({$category->productCount})",
                ));
            }
        }

        $this->newLine();
        $this->info('Discovery only — nothing was written.');

        return self::SUCCESS;
    }

    /**
     * Markets this source can actually chart. Null on a bad --market.
     *
     * Asked of the registry rather than derived from the market — bol not
     * operating in Spain is the connector's fact to know, and the next source
     * will draw its own map.
     *
     * @return list<Market>|null
     */
    private function markets(ConnectorRegistry $registry, Source $source): ?array
    {
        $requested = $this->option('market');

        if ($requested !== null && Market::tryFrom($requested) === null) {
            $this->error("Unknown market \"{$requested}\". Known: ".implode(', ', Market::values()));

            return null;
        }

        return array_values(array_filter(
            Market::cases(),
            function (Market $m) use ($registry, $source, $requested): bool {
                if ($requested !== null && $m->value !== $requested) {
                    return false;
                }

                foreach ($registry->popularityFor($m) as $connector) {
                    if ($connector->source() === $source) {
                        return true;
                    }
                }

                return false;
            },
        ));
    }
}
