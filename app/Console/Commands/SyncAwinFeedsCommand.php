<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\Market;
use App\Enums\Source;
use App\Models\Feed;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use League\Csv\Reader;

/**
 * Discovers which Awin advertiser feeds serve which market, and registers them.
 *
 * Matching feeds to markets correctly is the whole point. A Belgian-Dutch feed
 * carries Belgian prices, Belgian stock and Belgian delivery — serving it to the
 * Dutch market would put the wrong prices in front of the wrong shoppers, which
 * is the same class of error that market-scoped product identity exists to
 * prevent.
 *
 * Awin reports language as a full English word ("dutch", "french"), not an ISO
 * code, and region as a country code. Both have to match.
 */
class SyncAwinFeedsCommand extends Command
{
    protected $signature = 'bc:awin-feeds
        {--min-products=100 : Skip feeds smaller than this}
        {--limit= : Register at most this many feeds per market, largest first}
        {--enable : Enable the feeds it registers (default: register disabled)}
        {--dry-run : Show what would happen and change nothing}';

    protected $description = 'Discover Awin advertiser feeds and register them against the right market';

    /**
     * Awin's region + language, per market.
     *
     * `en` and `es` have no entry because the publisher account has no feeds
     * for them — see the summary this command prints.
     */
    private const MARKET_MAP = [
        'be-nl' => ['region' => 'BE', 'language' => 'dutch'],
        'be-fr' => ['region' => 'BE', 'language' => 'french'],
        'nl-nl' => ['region' => 'NL', 'language' => 'dutch'],
        'es' => ['region' => 'ES', 'language' => 'spanish'],
        'en' => ['region' => 'GB', 'language' => 'english'],
    ];

    public function handle(): int
    {
        $token = (string) config('brandcoves.connectors.awin.api_token');
        if ($token === '') {
            $this->error('AWIN_API_TOKEN is not set.');

            return self::FAILURE;
        }

        $this->line('Fetching the Awin feed list…');
        $response = Http::timeout(60)->get("https://productdata.awin.com/datafeed/list/apikey/{$token}/");

        if ($response->failed()) {
            $this->error("Awin returned HTTP {$response->status()}.");

            return self::FAILURE;
        }

        $csv = Reader::createFromString($response->body());
        $csv->setHeaderOffset(0);

        $available = [];
        foreach ($csv->getRecords() as $record) {
            // Only advertisers this publisher is actually approved for; the
            // list otherwise includes every advertiser on the network.
            if (($record['Membership Status'] ?? '') !== 'active') {
                continue;
            }

            $id = trim((string) ($record['Feed ID'] ?? ''));
            if ($id === '') {
                continue;
            }

            $available[$id] = [
                'id' => $id,
                'advertiser' => trim((string) ($record['Advertiser Name'] ?? '')),
                'region' => strtoupper(trim((string) ($record['Primary Region'] ?? ''))),
                'language' => strtolower(trim((string) ($record['Language'] ?? ''))),
                'products' => (int) str_replace([',', '.'], '', (string) ($record['No of products'] ?? '0')),
            ];
        }

        $this->info(count($available).' active feeds available.');
        $this->newLine();

        $minProducts = (int) $this->option('min-products');
        $limit = $this->option('limit') === null ? null : (int) $this->option('limit');
        $registered = 0;

        foreach (Market::cases() as $market) {
            $want = self::MARKET_MAP[$market->value] ?? null;

            $matched = $want === null ? [] : array_filter(
                $available,
                fn (array $f) => $f['region'] === $want['region']
                    && $f['language'] === $want['language']
                    // A feed of 12 products is not worth an hourly download.
                    && $f['products'] >= $minProducts,
            );

            // Largest first: catalogue breadth is the binding constraint on
            // Daily Picks and the gift engine.
            uasort($matched, fn ($a, $b) => $b['products'] <=> $a['products']);

            if ($limit !== null) {
                $matched = array_slice($matched, 0, $limit, true);
            }

            $this->line(sprintf(
                '<info>%s</info>  %d feeds, %s products',
                str_pad($market->value, 6),
                count($matched),
                number_format(array_sum(array_column($matched, 'products'))),
            ));

            if ($matched === []) {
                $this->line('        <comment>no Awin coverage for this market</comment>');
                $this->newLine();

                continue;
            }

            foreach ($matched as $feed) {
                $this->line(sprintf('        %-8s %-32s %s products',
                    $feed['id'],
                    mb_substr($feed['advertiser'], 0, 32),
                    number_format($feed['products']),
                ));

                if ($this->option('dry-run')) {
                    continue;
                }

                Feed::updateOrCreate(
                    [
                        'source' => Source::Awin,
                        'external_feed_id' => $feed['id'],
                        'market' => $market,
                    ],
                    [
                        'label' => $feed['advertiser'],
                        // Registered disabled by default: turning on 30 feeds at
                        // once means 30 concurrent multi-hundred-megabyte
                        // downloads on the next scheduled run.
                        'enabled' => (bool) $this->option('enable'),
                    ],
                );
                $registered++;
            }

            $this->newLine();
        }

        if ($this->option('dry-run')) {
            $this->comment('Dry run — nothing was written.');

            return self::SUCCESS;
        }

        $this->info("Registered or updated {$registered} feeds.");

        if (! $this->option('enable')) {
            $this->comment('All registered disabled. Enable the ones you want in /admin/feeds, then run bc:ingest.');
        }

        return self::SUCCESS;
    }
}
