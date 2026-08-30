<?php

declare(strict_types=1);

namespace App\Services\Catalogue;

use App\Enums\Market;
use App\Enums\Source;
use App\Models\Feed;
use Illuminate\Support\Facades\Http;
use League\Csv\Reader;

/**
 * Which Awin advertiser feeds serve which market.
 *
 * Extracted from `bc:awin-feeds` so the admin can run the same discovery. The
 * console command and the Filament page were otherwise going to hold two copies
 * of the market-matching rules, and the copy that drifts is the one that quietly
 * serves Belgian prices to Dutch shoppers.
 *
 * Matching feeds to markets correctly is the whole point. A Belgian-Dutch feed
 * carries Belgian prices, Belgian stock and Belgian delivery; serving it to the
 * Dutch market is the same class of error that market-scoped product identity
 * exists to prevent.
 *
 * Awin reports language as a full English word ("dutch", "french"), not an ISO
 * code, and region as a country code. Both have to match.
 */
class AwinFeedDiscovery
{
    /**
     * Awin's region + language, per market.
     *
     * `en` and `es` have no entry because the publisher account has no feeds
     * for them.
     */
    public const MARKET_MAP = [
        'be-nl' => ['region' => 'BE', 'language' => 'dutch'],
        'be-fr' => ['region' => 'BE', 'language' => 'french'],
        'nl-nl' => ['region' => 'NL', 'language' => 'dutch'],
        'es' => ['region' => 'ES', 'language' => 'spanish'],
        'en' => ['region' => 'GB', 'language' => 'english'],
    ];

    /** @var list<string> Accounts that could not be reached on the last run. */
    public array $warnings = [];

    /**
     * Every active feed on every configured account.
     *
     * Each account is queried and each feed remembers which one it came from.
     * An advertiser is only reachable through the publisher account joined to
     * them — Vanden Borre is simply absent from the primary account's list, not
     * marked "Not Joined", so there is no way to reach it without its own
     * credentials.
     *
     * One bad account must not stop the others: a revoked key is a
     * configuration problem, not a reason to leave the rest unregistered.
     *
     * @return array<string, array<string, mixed>>
     */
    public function available(): array
    {
        $this->warnings = [];
        $available = [];

        foreach ((array) config('giftcoves.connectors.awin.accounts', []) as $key => $account) {
            $response = Http::timeout(60)
                ->get("https://productdata.awin.com/datafeed/list/apikey/{$account['api_token']}/");

            if ($response->failed()) {
                $this->warnings[] = "{$account['label']}: Awin returned HTTP {$response->status()}.";

                continue;
            }

            $csv = Reader::createFromString($response->body());
            $csv->setHeaderOffset(0);

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
                    'account' => (string) $key,
                    'accountLabel' => (string) $account['label'],
                    'advertiser' => trim((string) ($record['Advertiser Name'] ?? '')),
                    'advertiserId' => trim((string) ($record['Advertiser ID'] ?? '')),
                    'region' => strtoupper(trim((string) ($record['Primary Region'] ?? ''))),
                    'language' => strtolower(trim((string) ($record['Language'] ?? ''))),
                    'products' => self::number($record['No of products'] ?? $record['Number of products'] ?? '0'),

                    /*
                     * Everything below is for a person choosing, not for the
                     * matching rules.
                     *
                     * Awin's column set is not a contract — it has gained and
                     * lost columns without notice — so each is read with a
                     * fallback and an empty string is a perfectly good answer.
                     * A missing column must never be able to break discovery,
                     * because discovery is how shops get added at all.
                     */
                    'feedName' => trim((string) ($record['Feed Name'] ?? '')),
                    'vertical' => trim((string) ($record['Vertical'] ?? $record['Sector'] ?? '')),
                    'currency' => strtoupper(trim((string) ($record['Currency'] ?? ''))),
                    'commission' => trim((string) ($record['Commission'] ?? '')),
                    // The one that answers "is this feed still alive?". A feed
                    // Awin last imported months ago is a shop that has stopped
                    // publishing, and registering it buys an empty download.
                    'lastImported' => trim((string) ($record['Last Imported'] ?? '')),
                    'lastChecked' => trim((string) ($record['Last Checked'] ?? '')),
                ];
            }
        }

        return $available;
    }

    /**
     * Awin writes counts with thousands separators, and not always the same one.
     *
     * "4,000" from one account and "4.000" from another is enough to turn a
     * four-thousand-product feed into a four, which the minimum-products filter
     * then quietly discards.
     */
    private static function number(mixed $value): int
    {
        return (int) preg_replace('/\D/', '', (string) $value);
    }

    /**
     * Which market a single feed belongs to, or null for one we cannot serve.
     *
     * The same region-and-language rule `perMarket()` filters on, asked one feed
     * at a time — because the picker shows an editor *everything* Awin offers and
     * has to label each row, where `perMarket()` answers the different question
     * "which feeds are worth registering".
     *
     * Both read `MARKET_MAP`, so there is still one place that decides. A second
     * copy of this rule is how a Belgian feed ends up serving Dutch shoppers
     * Belgian prices, stock and delivery — the same class of error that
     * market-scoped product identity exists to prevent.
     *
     * @param  array{region: string, language: string}  $feed
     */
    public function marketFor(array $feed): ?string
    {
        foreach (self::MARKET_MAP as $market => $want) {
            if ($feed['region'] === $want['region'] && $feed['language'] === $want['language']) {
                return $market;
            }
        }

        return null;
    }

    /**
     * The feeds worth registering, grouped by market.
     *
     * One feed per ADVERTISER, largest first — not simply the largest feeds.
     * Retailers publish many category feeds, so ranking by size alone returns
     * six slices of one shop. Offer comparison needs the same product at
     * *different* merchants, and a catalogue of one retailer produces zero
     * comparable products no matter how many rows it has. Breadth of merchants
     * beats depth of catalogue.
     *
     * @param  array<string, mixed>  $available
     * @param  list<string>  $only  advertiser names; empty means "the allowlist"
     * @return array<string, list<array<string, mixed>>>
     */
    public function perMarket(
        array $available,
        int $minProducts = 100,
        ?int $limit = null,
        bool $all = false,
        array $only = [],
    ): array {
        $out = [];

        foreach (Market::cases() as $market) {
            $want = self::MARKET_MAP[$market->value] ?? null;

            $matched = $want === null ? [] : array_filter(
                $available,
                fn (array $f) => $f['region'] === $want['region']
                    && $f['language'] === $want['language']
                    // A feed of 12 products is not worth an hourly download.
                    && $f['products'] >= $minProducts
                    && $this->isWanted($f['advertiser'], $all, $only),
            );

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

            $out[$market->value] = array_values(
                $limit === null ? $byAdvertiser : array_slice($byAdvertiser, 0, $limit, true)
            );
        }

        return $out;
    }

    /**
     * Write the feeds to the catalogue.
     *
     * **`enabled` is only ever written on create, or when explicitly enabling.**
     * It used to be part of the `updateOrCreate` payload, which meant a plain
     * re-run of discovery — the obvious thing to do, and now a button in the
     * admin — silently switched off every feed that was already running. The
     * catalogue would then empty itself over the following days with nothing on
     * screen to say why.
     *
     * @param  list<array<string, mixed>>  $feeds
     * @return array{created: int, updated: int, enabled: int}
     */
    public function register(string $market, array $feeds, bool $enable): array
    {
        $created = $updated = $enabled = 0;

        foreach ($feeds as $feed) {
            $row = Feed::query()->firstOrNew([
                'source' => Source::Awin,
                'external_feed_id' => $feed['id'],
                'market' => $market,
            ]);

            $isNew = ! $row->exists;

            $row->fill([
                'label' => $feed['advertiser'],
                // Which credentials to download it with. Without this the
                // connector would use the primary key and get a 401.
                'account' => $feed['account'],
            ]);

            if ($isNew) {
                // Registered disabled by default: turning on thirty feeds at
                // once means thirty concurrent multi-hundred-megabyte downloads
                // on the next scheduled run.
                $row->enabled = $enable;
            } elseif ($enable && ! $row->enabled) {
                $row->enabled = true;
                $enabled++;
            }

            $row->save();

            $isNew ? $created++ : $updated++;
        }

        return ['created' => $created, 'updated' => $updated, 'enabled' => $enabled];
    }

    /**
     * Whether an advertiser is wanted.
     *
     * Matched loosely: Awin writes "Vanden Borre BE", "Krefel BE",
     * "Coolblue NL", and the exact spelling changes without warning. Comparing
     * a stripped, lowercased form survives that — an allowlist that silently
     * stops matching would quietly empty the catalogue.
     *
     * @param  list<string>  $only
     */
    private function isWanted(string $advertiser, bool $all, array $only): bool
    {
        // An explicit request beats both the allowlist and `--all`: "add Vanden
        // Borre" should add Vanden Borre and nothing else.
        $wanted = $only !== []
            ? $only
            : ($all ? [] : (array) config('giftcoves.connectors.awin.advertisers', []));

        if ($only === [] && ($all || $wanted === [])) {
            return true;
        }

        $normalise = fn (string $v) => strtolower((string) preg_replace('/[^a-z0-9]/i', '', $v));
        $name = $normalise($advertiser);

        foreach ($wanted as $candidate) {
            if ($name !== '' && str_contains($name, $normalise((string) $candidate))) {
                return true;
            }
        }

        return false;
    }
}
