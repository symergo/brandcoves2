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
        {--limit= : Register at most this many merchants per market, largest first}
        {--all : Ignore the advertiser allowlist and show everything available}
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

    /**
     * Whether an advertiser is on the allowlist.
     *
     * Matched loosely: Awin writes "Vanden Borre BE", "Krefel BE",
     * "Coolblue NL", and the exact spelling changes without warning. Comparing
     * a stripped, lowercased form survives that — an allowlist that silently
     * stops matching would quietly empty the catalogue.
     */
    private function isWanted(string $advertiser): bool
    {
        if ($this->option('all')) {
            return true;
        }

        $allowed = (array) config('brandcoves.connectors.awin.advertisers', []);
        if ($allowed === []) {
            return true;
        }

        $normalise = fn (string $v) => strtolower((string) preg_replace('/[^a-z0-9]/i', '', $v));
        $name = $normalise($advertiser);

        foreach ($allowed as $wanted) {
            if ($name !== '' && str_contains($name, $normalise((string) $wanted))) {
                return true;
            }
        }

        return false;
    }

    public function handle(): int
    {
        $accounts = (array) config('brandcoves.connectors.awin.accounts', []);

        if ($accounts === []) {
            $this->error('No Awin account has an API token configured.');

            return self::FAILURE;
        }

        /*
         * Every account is queried, and each feed remembers which one it came
         * from.
         *
         * An advertiser is only reachable through the publisher account joined
         * to them — a different affiliate member id sees a different advertiser
         * list entirely. Vanden Borre is simply absent from the primary
         * account's list, not marked "Not Joined", so there is no way to reach
         * it without its own credentials.
         */
        $available = [];

        foreach ($accounts as $key => $account) {
            $this->line("Fetching the feed list for <info>{$account['label']}</info>…");

            $response = Http::timeout(60)
                ->get("https://productdata.awin.com/datafeed/list/apikey/{$account['api_token']}/");

            if ($response->failed()) {
                // One bad account must not stop the others. A wrong or revoked
                // key is a configuration problem, not a reason to leave the
                // rest of the catalogue unregistered.
                $this->warn("  {$account['label']}: Awin returned HTTP {$response->status()} — skipped.");

                continue;
            }

            $csv = Reader::createFromString($response->body());
            $csv->setHeaderOffset(0);

            $found = 0;
            foreach ($csv->getRecords() as $record) {
                // Only advertisers this account is actually approved for; the
                // list otherwise includes every advertiser on the network.
                if (($record['Membership Status'] ?? '') !== 'active') {
                    continue;
                }

                $id = trim((string) ($record['Feed ID'] ?? ''));
                if ($id === '') {
                    continue;
                }

                // Keyed by account AND feed id: two accounts can legitimately
                // both be joined to the same advertiser.
                $available[$key.':'.$id] = [
                    'id' => $id,
                    'account' => $key,
                    'accountLabel' => $account['label'],
                    'advertiser' => trim((string) ($record['Advertiser Name'] ?? '')),
                    'region' => strtoupper(trim((string) ($record['Primary Region'] ?? ''))),
                    'language' => strtolower(trim((string) ($record['Language'] ?? ''))),
                    'products' => (int) str_replace([',', '.'], '', (string) ($record['No of products'] ?? '0')),
                ];
                $found++;
            }

            $this->line("  {$found} active feeds.");
        }

        if ($available === []) {
            $this->error('No active feeds found on any account.');

            return self::FAILURE;
        }

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
                    && $f['products'] >= $minProducts
                    && $this->isWanted($f['advertiser']),
            );

            /*
             * One feed per ADVERTISER, largest first — not simply the largest
             * feeds.
             *
             * Retailers publish many category feeds, so ranking by size alone
             * returns six slices of one shop. That is useless here: offer
             * comparison needs the same product at *different* merchants, and a
             * catalogue of one retailer produces zero comparable products no
             * matter how many rows it has.
             *
             * Breadth of merchants beats depth of catalogue.
             */
            $byAdvertiser = [];
            foreach ($matched as $feed) {
                // Keyed on the advertiser alone, not advertiser+account: if two
                // accounts both reach a shop we want one feed, not a duplicate
                // that would double every offer and inflate the merchant count.
                $key = strtolower($feed['advertiser']);
                if (! isset($byAdvertiser[$key]) || $feed['products'] > $byAdvertiser[$key]['products']) {
                    $byAdvertiser[$key] = $feed;
                }
            }

            uasort($byAdvertiser, fn ($a, $b) => $b['products'] <=> $a['products']);

            $matched = $limit === null ? $byAdvertiser : array_slice($byAdvertiser, 0, $limit, true);

            $this->line(sprintf(
                '<info>%s</info>  %d merchants, %s products',
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
                $this->line(sprintf('        %-8s %-30s %-12s %s products',
                    $feed['id'],
                    mb_substr($feed['advertiser'], 0, 30),
                    '['.$feed['account'].']',
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
                        // Which credentials to download it with. Without this the
                        // connector would use the primary key and get a 401.
                        'account' => $feed['account'],
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
