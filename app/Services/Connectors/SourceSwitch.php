<?php

declare(strict_types=1);

namespace App\Services\Connectors;

use App\Enums\Market;
use App\Enums\Source;
use App\Models\ConnectorSetting;
use App\Services\Settings\AiSettingsStore;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Per-market on/off for every source, editable from the panel.
 *
 * ## The gap this fills
 *
 * There were two ways to stop a source and neither was the one anybody wanted.
 *
 * `giftcoves.connectors.*.enabled` is global and lives in the environment, so
 * turning bol off for Spain alone was impossible and turning it off at all was
 * a redeploy — the same friction {@see AiSettingsStore}
 * exists to remove for AI. The other way was per-market and accidental: eBay and
 * Tradedoubler skip a market whose marketplace or query scoping is blank
 * ({@see Market::ebayMarketplace()}, {@see Market::tradedoublerQuery()}), which
 * is a statement that *the source does not serve there*, not that we chose to
 * stop asking. bol has no such lever at all — {@see Market::bolCountry()} is a
 * match arm, so switching bol off for one market was a code change.
 *
 * Conflating the two would make the diagnostic lie: switch eBay off for `es` by
 * blanking its marketplace and Market supply reports "no marketplace mapped",
 * sending the next person to fix an environment variable that is not broken. So
 * this is a third, separate fact, and it reads as one.
 *
 * ## Why not overlaid onto config
 *
 * `AiSettingsStore` writes its values *into* the config at boot, and its reason
 * was that `AiClient`, `AiUsage` and the usage table already read
 * `config('giftcoves.ai.*')` — an overlay meant not changing them and not having
 * two ways to ask one question.
 *
 * None of that applies here: per-market enablement is a dimension no config key
 * has ever had, so there is no existing reader to preserve. Inventing
 * `giftcoves.connectors.ebay.markets.es` purely to have something to overlay
 * would add a config surface whose only writer is this class and whose only
 * reader is this class.
 *
 * ## Default on, and only overrides are stored
 *
 * A source with no row is enabled. That keeps the table proportional to the
 * decisions actually taken — a fresh install stores nothing — and makes "undo"
 * expressible as deleting a row rather than writing `true`, which is the shape
 * `AiSettingsStore::put()` already uses for falling back to the environment.
 *
 * ## What it does NOT do
 *
 * Switching a source off stops us *asking* it. It does not retract what it has
 * already stored: a feed source's rows stay in `products` and keep appearing in
 * search, because they are a catalogue, not a cache. `bc:withdraw-source` is the
 * tool that suppresses those, and it deliberately refuses to run while the
 * source still serves the market — so the order is switch off here, withdraw
 * there. The panel says so at the point of the click.
 */
class SourceSwitch
{
    /**
     * One row per source, holding a map of market value => bool.
     *
     * A row per (source, market) would be thirty rows to express "eBay is off in
     * Spain" plus twenty-nine defaults, and every read would decrypt rows that
     * say nothing. `encrypted_value` is cast `encrypted:json`, so a map costs one
     * row and one decrypt.
     */
    private const KEY = 'markets';

    private const CACHE_KEY = 'bc:settings:source-switch';

    /**
     * An hour, flushed on write.
     *
     * These change by hand and are read on every search, so the cache is what
     * keeps this off the hot path. Nobody waits for it: {@see self::set()}
     * forgets the key, so a toggle is visible on the next request.
     */
    private const CACHE_TTL = 3600;

    /**
     * Whether this source may be asked about this market.
     *
     * Deliberately narrow. It answers only the question this class owns — the
     * global config switch, the credentials and the market mapping are each
     * checked by the connector's own `supports()`, which stays the authority on
     * whether a source serves a market at all.
     */
    public function isEnabled(Source $source, Market $market): bool
    {
        return (bool) ($this->matrix()[$source->value][$market->value] ?? true);
    }

    /**
     * Markets this source has been switched off in, in enum order.
     *
     * @return list<Market>
     */
    public function disabledMarkets(Source $source): array
    {
        return array_values(array_filter(
            Market::cases(),
            fn (Market $market): bool => ! $this->isEnabled($source, $market),
        ));
    }

    /**
     * Turn one source on or off in one market.
     *
     * `true` deletes the entry rather than storing it, so the table only ever
     * holds decisions that differ from the default and a source switched back on
     * leaves nothing behind.
     */
    public function set(Source $source, Market $market, bool $enabled): void
    {
        $markets = $this->matrix()[$source->value] ?? [];

        if ($enabled) {
            unset($markets[$market->value]);
        } else {
            $markets[$market->value] = false;
        }

        if ($markets === []) {
            ConnectorSetting::query()
                ->where('source', $source->value)
                ->where('key', self::KEY)
                ->delete();
        } else {
            ConnectorSetting::updateOrCreate(
                ['source' => $source->value, 'key' => self::KEY],
                ['encrypted_value' => $markets],
            );
        }

        $this->flush();
    }

    /** Turn a source on or off across every market in one gesture. */
    public function setAll(Source $source, bool $enabled): void
    {
        foreach (Market::cases() as $market) {
            $this->set($source, $market, $enabled);
        }
    }

    /**
     * Every stored override, as source value => market value => false.
     *
     * @return array<string, array<string, bool>>
     */
    public function matrix(): array
    {
        /*
         * The try wraps the CACHE CALL, not the query inside it.
         *
         * Straight from AiSettingsStore, and the distinction is load-bearing for
         * the same reason: `package:discover` boots the application during the
         * Docker build, where there is no Postgres and no Redis, so the cache
         * store falls back to the database driver and the *lookup* throws
         * several frames before the query this guards. There are three ways this
         * runs without a reachable database — a build, `migrate` against a fresh
         * schema, and a test that has not migrated — and in all three the right
         * answer is the same: no overrides, every source on, boot completes.
         *
         * A connector that threw here would take search down on a machine whose
         * only fault was an unreachable Redis.
         */
        try {
            return Cache::remember(self::CACHE_KEY, self::CACHE_TTL, function (): array {
                return ConnectorSetting::query()
                    ->where('key', self::KEY)
                    ->get()
                    ->mapWithKeys(fn (ConnectorSetting $s): array => [
                        $s->source => $this->clean($s->encrypted_value),
                    ])
                    ->all();
            });
        } catch (Throwable) {
            return [];
        }
    }

    public function flush(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    /**
     * Keep only recognised markets, and only the "off" entries.
     *
     * A stored map outlives the code that wrote it: a market removed from the
     * enum leaves an entry addressing nothing, and reading it back would put a
     * key in the matrix that no page can render and no toggle can clear. Dropped
     * on read rather than migrated, because the honest default for an unknown
     * market is the same as for a market nobody has touched.
     *
     * @return array<string, bool>
     */
    private function clean(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $known = Market::values();
        $clean = [];

        foreach ($value as $market => $enabled) {
            if (is_string($market) && in_array($market, $known, true) && ! $enabled) {
                $clean[$market] = false;
            }
        }

        return $clean;
    }
}
