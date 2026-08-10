<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\Market;
use App\Services\Catalogue\AwinFeedDiscovery;
use Illuminate\Console\Command;

/**
 * Discovers which Awin advertiser feeds serve which market, and registers them.
 *
 * The rules live in {@see AwinFeedDiscovery}, which the admin's "Discover feeds"
 * button also calls. This is the console face of it: options in, a table out.
 */
class SyncAwinFeedsCommand extends Command
{
    protected $signature = 'bc:awin-feeds
        {--min-products=100 : Skip feeds smaller than this}
        {--limit= : Register at most this many merchants per market, largest first}
        {--all : Ignore the advertiser allowlist and show everything available}
        {--only= : Only these advertisers, comma separated. Beats the allowlist.}
        {--enable : Enable the feeds it registers}
        {--dry-run : Show what would happen and change nothing}';

    protected $description = 'Discover Awin advertiser feeds and register them against the right market';

    public function handle(AwinFeedDiscovery $discovery): int
    {
        $accounts = (array) config('brandcoves.connectors.awin.accounts', []);

        if ($accounts === []) {
            $this->error('No Awin account has an API token configured.');

            return self::FAILURE;
        }

        $this->reportAccounts($accounts);

        $this->line('Fetching the feed lists…');

        $available = $discovery->available();

        foreach ($discovery->warnings as $warning) {
            $this->warn("  {$warning}");
        }

        if ($available === []) {
            $this->error('No active feeds found on any account.');

            return self::FAILURE;
        }

        $this->line('  '.count($available).' active feeds.');
        $this->newLine();

        $only = array_values(array_filter(array_map(
            trim(...),
            explode(',', (string) $this->option('only')),
        )));

        $perMarket = $discovery->perMarket(
            $available,
            (int) $this->option('min-products'),
            $this->option('limit') === null ? null : (int) $this->option('limit'),
            (bool) $this->option('all'),
            $only,
        );

        $totals = ['created' => 0, 'updated' => 0, 'enabled' => 0];

        foreach (Market::cases() as $market) {
            $matched = $perMarket[$market->value] ?? [];

            $this->line(sprintf(
                '<info>%s</info>  %d merchants, %s products',
                str_pad($market->value, 6),
                count($matched),
                number_format(array_sum(array_column($matched, 'products'))),
            ));

            foreach ($matched as $feed) {
                $this->line(sprintf('        %-8s %-30s %-12s %s products',
                    $feed['id'],
                    mb_substr($feed['advertiser'], 0, 30),
                    '['.$feed['account'].']',
                    number_format($feed['products']),
                ));
            }

            if ($matched === []) {
                $this->line('        <comment>nothing matched for this market</comment>');
            }

            if (! $this->option('dry-run') && $matched !== []) {
                $result = $discovery->register($market->value, $matched, (bool) $this->option('enable'));

                foreach ($totals as $key => $value) {
                    $totals[$key] = $value + $result[$key];
                }
            }

            $this->newLine();
        }

        if ($this->option('dry-run')) {
            $this->comment('Dry run — nothing was written.');

            return self::SUCCESS;
        }

        $this->info(sprintf(
            '%d registered, %d updated, %d switched on.',
            $totals['created'],
            $totals['updated'],
            $totals['enabled'],
        ));

        if (! $this->option('enable')) {
            $this->comment('New feeds are registered disabled. Enable them in /admin/feeds, then run bc:ingest.');
        }

        return self::SUCCESS;
    }

    /**
     * Say which accounts are in play before spending a minute discovering feeds.
     *
     * An advertiser is only reachable through the account joined to them, so a
     * missing account does not produce an error — it produces a shorter feed
     * list, which looks exactly like an advertiser having no feeds. That is how
     * `AWIN_VDB_*` went unnoticed: set locally, never passed through the compose
     * file, so every deployed run quietly discovered one account's merchants and
     * reported complete success.
     *
     * Printing this first means the discrepancy is visible above the results
     * rather than inferred from them.
     *
     * @param  array<string, array{label?: string}>  $accounts
     */
    private function reportAccounts(array $accounts): void
    {
        /** @var array<string, array{label: string, env: string}> $declared */
        $declared = (array) config('brandcoves.connectors.awin.declared_accounts', []);

        foreach ($declared as $key => $meta) {
            if (array_key_exists($key, $accounts)) {
                $this->line("  <info>✓</info> {$meta['label']} ({$key})");

                continue;
            }

            $this->warn("  ✗ {$meta['label']} ({$key}) — {$meta['env']} is unset here, so its advertisers will be absent from everything below.");
        }

        // Accounts configured but never declared: possible after a rename, and
        // worth saying rather than quietly including.
        foreach (array_diff(array_keys($accounts), array_keys($declared)) as $undeclared) {
            $this->warn("  ? {$undeclared} has a token but is not in declared_accounts.");
        }

        $this->newLine();
    }
}
