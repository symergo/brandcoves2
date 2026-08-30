<?php

declare(strict_types=1);

namespace App\Services\Ops;

use App\Enums\Market;
use App\Enums\ProductStatus;
use App\Enums\Source;
use App\Services\Connectors\ConnectorRegistry;
use App\Services\Connectors\LiveConnector;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Which sources actually sell into which market, as data.
 *
 * ## The question this exists to answer
 *
 * The admin could say *this feed belongs to that market* — the Feeds table has
 * a market column — and could not say the reverse: *what supply does this market
 * have?* For the feed sources that was a counting exercise nobody did. For the
 * live sources it was not answerable at all, because they have no rows.
 *
 * A live source's market coverage is *computed*, per request, from config:
 * bol needs {@see Market::bolCountry()}, eBay a marketplace, Tradedoubler a
 * query scoping. {@see ConnectorRegistry::liveSourcesFor()} is the authority and
 * its only callers were controllers, so the panel showed nothing. The nearest
 * thing was the config report on the migration screen, which is global — it says
 * `EBAY_CLIENT_ID` is set, not that `es` is dark. Per-market truth lived on the
 * console, in `bc:check-bol`, `bc:check-ebay` and `bc:check-tradedoubler`, which
 * is exactly the SSH session the Awin feed-discovery screen was built to remove
 * for Awin.
 *
 * ## A service, not a page method
 *
 * Same argument as {@see ConfigReport}: two implementations of "is this market
 * supplied" would drift, and the one that drifts is the one somebody is reading
 * at the time. This is pure data, so the rules are testable without rendering
 * anything.
 *
 * ## What it never does
 *
 * No credential values, only their presence — this renders into an HTML page
 * that gets screenshotted. And no network: every answer here comes from the
 * database and from `config()`, so opening the page cannot spend an API budget
 * or hang on an upstream that is down. Proving a credential actually works is
 * still the `bc:check-*` commands' job, and the page says so.
 */
class MarketSupply
{
    /**
     * One minute for the row counts.
     *
     * Long enough that Livewire re-rendering the page on every click does not
     * re-count the catalogue, short enough that somebody watching an ingestion
     * land sees it. Only the database aggregates are cached: connector and
     * config state is free to evaluate and is read live every render, so
     * somebody who has just fixed an env var is not told to wait a minute.
     */
    private const CACHE_TTL = 60;

    private const CACHE_KEY = 'bc:market-supply-counts';

    public function __construct(private readonly ConnectorRegistry $registry) {}

    /**
     * The sources worth a column, in enum order.
     *
     * `manual` is excluded: it is how a human pins one offer, not a channel a
     * market can be supplied through, and a column that reads "not integrated"
     * on every row for a source that is working as designed is noise.
     *
     * @return list<Source>
     */
    public function sources(): array
    {
        return array_values(array_filter(
            Source::cases(),
            fn (Source $source): bool => $source->isFeed() || $source->isLive(),
        ));
    }

    /**
     * One row per market — every market, not just the published ones.
     *
     * An unpublished market is precisely the one this page is for: `es` is
     * closed *because* it has no supply, and the way it reopens is these cells
     * turning green. Hiding it would hide the work.
     *
     * @return list<array{
     *     market: Market,
     *     published: bool,
     *     serving: int,
     *     groups: int,
     *     offers: int,
     *     cells: list<array{source: Source, kind: string, status: string, headline: string, notes: list<string>, earning: ?string}>,
     * }>
     */
    public function rows(): array
    {
        $counts = $this->counts();

        return array_map(function (Market $market) use ($counts): array {
            $cells = array_map(
                fn (Source $source): array => $source->isFeed()
                    ? $this->feedCell($source, $market, $counts['feeds'][$market->value.'|'.$source->value] ?? [])
                    : $this->liveCell($source, $market),
                $this->sources(),
            );

            return [
                'market' => $market,
                'published' => $market->isPublished(),
                'serving' => count(array_filter($cells, fn (array $cell): bool => $cell['status'] === 'ok')),
                'groups' => (int) ($counts['groups'][$market->value] ?? 0),
                'offers' => (int) ($counts['offers'][$market->value] ?? 0),
                'cells' => $cells,
            ];
        }, Market::cases());
    }

    /** Markets with nothing serving them at all, by value. @return list<string> */
    public function darkMarkets(): array
    {
        return array_values(array_map(
            fn (array $row): string => $row['market']->value,
            array_filter($this->rows(), fn (array $row): bool => $row['serving'] === 0),
        ));
    }

    public function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    /**
     * A feed source's standing in one market.
     *
     * The distinction that matters is between *nobody registered a feed*,
     * *somebody registered one and switched it off*, and *it is on and
     * failing*. All three render as an empty catalogue and each needs different
     * work, so none of them may collapse into "no supply".
     *
     * @param  array{total?: int, enabled?: int, failing?: int, never_run?: int, last_run_at?: ?string}  $totals
     * @return array{source: Source, kind: string, status: string, headline: string, notes: list<string>, earning: ?string}
     */
    private function feedCell(Source $source, Market $market, array $totals): array
    {
        $total = (int) ($totals['total'] ?? 0);
        $enabled = (int) ($totals['enabled'] ?? 0);
        $failing = (int) ($totals['failing'] ?? 0);
        $neverRun = (int) ($totals['never_run'] ?? 0);
        $lastRun = $totals['last_run_at'] ?? null;

        $notes = [];

        [$status, $headline] = match (true) {
            $total === 0 => ['absent', 'no feeds registered'],
            $enabled === 0 => ['off', $total.' registered, none enabled'],
            $failing === $enabled => ['failing', 'all '.$enabled.' failing'],
            $lastRun === null => ['pending', $enabled.' enabled, never run'],
            default => ['ok', $enabled.' enabled'],
        };

        // A partial failure is the one that hides: the catalogue still fills, so
        // nothing on the site looks wrong, and one merchant has quietly gone.
        if ($failing > 0 && $failing < $enabled) {
            $notes[] = $failing.' of '.$enabled.' failing';
        }

        if ($neverRun > 0 && $status === 'ok') {
            $notes[] = $neverRun.' never run';
        }

        if ($lastRun !== null) {
            // Relative, and formatted here rather than in the view: every other
            // string in this cell is already prose, and a page that renders
            // half its facts and formats the other half is two places to change.
            $notes[] = 'last run '.Carbon::parse($lastRun)->diffForHumans();
        }

        if ($total > $enabled && $enabled > 0) {
            $notes[] = ($total - $enabled).' disabled';
        }

        return [
            'source' => $source,
            'kind' => 'feed',
            'status' => $status,
            'headline' => $headline,
            'notes' => $notes,
            'earning' => null,
        ];
    }

    /**
     * A live source's standing in one market.
     *
     * @return array{source: Source, kind: string, status: string, headline: string, notes: list<string>, earning: ?string}
     */
    private function liveCell(Source $source, Market $market): array
    {
        $connector = $this->registry->live($source);

        if ($connector === null) {
            return [
                'source' => $source,
                'kind' => 'live',
                'status' => 'absent',
                'headline' => 'not integrated',
                // Worth stating rather than leaving blank, because the config
                // asks for this source's credentials and reads as though
                // supplying them would switch it on. It would not: there is no
                // connector class to switch on.
                'notes' => ['No connector is registered, so credentials alone will not make it appear in search.'],
                'earning' => null,
            ];
        }

        $supported = $connector->supports($market);

        $notes = $supported ? [] : $this->blockers($source, $market);

        if (! $supported && $notes === []) {
            // The explanation is advisory and supports() is authoritative, so
            // they can in principle disagree — a connector may grow a
            // precondition this class does not know about. Saying so beats a
            // cell that claims nothing is wrong while the source stays dark.
            $notes[] = 'Not serving this market, for a reason this page does not know about. Run the bc:check command for this source.';
        }

        if ($supported && $this->coolingDown($connector)) {
            // Not a status of its own: cooling down is this second's rate
            // limit, not a fact about the integration, and liveSourcesFor()
            // deliberately ignores it for the same reason.
            $notes[] = 'backing off after a 429 — searches skip it until the cooldown expires';
        }

        return [
            'source' => $source,
            'kind' => 'live',
            'status' => $supported ? 'ok' : 'off',
            'headline' => $supported ? 'serving' : 'not serving',
            'notes' => $notes,
            'earning' => $supported ? $this->earningGap($source, $market) : null,
        ];
    }

    /**
     * Why a live source is not serving a market.
     *
     * **Advisory, never authoritative.** `supports()` decides; this only
     * explains its answer, and the two are deliberately separate rather than
     * one shared method. Inverting a `supports()` implementation into a list of
     * reasons would mean either duplicating each connector's conditions here —
     * where they would drift — or bending four connectors to report diagnostics
     * they do not otherwise need. A wrong *explanation* is survivable and
     * caught by the fallback line in {@see self::liveCell()}; a wrong yes/no
     * would be a page that lies about coverage.
     *
     * Each reason names the variable that fixes it. "Not serving" without that
     * is the same dead end as the old discovery modal's "nothing matched".
     *
     * @return list<string>
     */
    private function blockers(Source $source, Market $market): array
    {
        $missing = static fn (string $path): bool => blank(config('giftcoves.connectors.'.$path));
        $off = static fn (string $path): bool => ! config('giftcoves.connectors.'.$path);

        $env = strtoupper(str_replace('-', '_', $market->value));

        return array_values(array_filter(match ($source) {
            Source::Bol => [
                $off('bol.enabled') ? 'switched off in config' : null,
                $missing('bol.client_id') || $missing('bol.client_secret')
                    ? 'credentials missing — BOL_CLIENT_ID and BOL_CLIENT_SECRET'
                    : null,
                $market->bolCountry() === null
                    ? 'bol does not operate here, so this market is feed-only'
                    : null,
            ],
            Source::Ebay => [
                $off('ebay.enabled') ? 'switched off — EBAY_ENABLED' : null,
                $missing('ebay.client_id') || $missing('ebay.client_secret')
                    ? 'credentials missing — EBAY_CLIENT_ID and EBAY_CLIENT_SECRET'
                    : null,
                $market->ebayMarketplace() === null
                    ? 'no marketplace mapped — EBAY_MARKETPLACE_'.$env.'. Blank means skip, never "use the default"'
                    : null,
            ],
            Source::Tradedoubler => [
                $off('tradedoubler.enabled') ? 'switched off — TRADEDOUBLER_ENABLED' : null,
                $missing('tradedoubler.token') ? 'token missing — TRADEDOUBLER_TOKEN' : null,
                $market->tradedoublerQuery() === null
                    ? 'no market scoping — TRADEDOUBLER_LANGUAGE_'.$env.'. Unscoped, it would return the whole European network'
                    : null,
            ],
            Source::Amazon => [
                $off('amazon.enabled') ? 'switched off — AMAZON_ENABLED' : null,
                $missing('amazon.access_key') || $missing('amazon.secret_key')
                    ? 'credentials missing — AMAZON_ACCESS_KEY and AMAZON_SECRET_KEY'
                    : null,
            ],
            default => [],
        }));
    }

    /**
     * Serving, and earning nothing on it.
     *
     * The failure this exists for is silent by construction: without a campaign
     * or site id the link still resolves, the visitor still buys, and the
     * commission goes to nobody. Nothing upstream reports it and no test can
     * catch it, so a green cell that is not actually paying has to say so on
     * its face. See {@see Market::ebayCampaignId()} and
     * {@see Market::bolPartnerSiteId()}.
     *
     * Tradedoubler has no equivalent: its one token is both the credential and
     * the affiliate id, so a source that answers at all is a source that earns.
     */
    private function earningGap(Source $source, Market $market): ?string
    {
        return match ($source) {
            Source::Bol => $market->bolPartnerSiteId() === null
                ? 'clicks earn nothing — no bol partner site id for '.($market->bolCountry() ?? '?')
                : null,
            Source::Ebay => $market->ebayCampaignId() === null
                ? 'clicks earn nothing — no EPN campaign id for '.($market->ebayMarketplace() ?? '?')
                : null,
            default => null,
        };
    }

    private function coolingDown(LiveConnector $connector): bool
    {
        try {
            return $connector->isCoolingDown();
        } catch (Throwable) {
            // The cooldown lives in Redis, and a page whose job is to explain an
            // outage must survive one. Unknown reads as "not backing off": the
            // cells around it are still true, and inventing a warning from a
            // failed lookup would send somebody chasing a rate limit that may
            // not exist.
            return false;
        }
    }

    /**
     * Every count the page needs, in three queries.
     *
     * @return array{feeds: array<string, array<string, mixed>>, groups: array<string, int>, offers: array<string, int>}
     */
    private function counts(): array
    {
        /** @var array{feeds: array<string, array<string, mixed>>, groups: array<string, int>, offers: array<string, int>} */
        return Cache::remember(self::CACHE_KEY, self::CACHE_TTL, function (): array {
            /*
             * DB::table, not the Feed model: the model casts `market` and
             * `source` to enums, and this row is an aggregate rather than a
             * feed — a Feed object whose `enabled` is a count of feeds is a
             * lie waiting to be read by the next person.
             */
            $feeds = DB::table('feeds')
                ->selectRaw('market, source')
                ->selectRaw('count(*) as total')
                // Postgres aggregate FILTER, which this project may use freely —
                // pg_trgm and unaccent already make it Postgres-only. One pass
                // over a small table beats four correlated counts.
                ->selectRaw('count(*) filter (where enabled) as enabled')
                ->selectRaw('count(*) filter (where enabled and last_error is not null) as failing')
                ->selectRaw('count(*) filter (where enabled and last_run_at is null) as never_run')
                ->selectRaw('max(last_run_at) as last_run_at')
                ->groupBy('market', 'source')
                ->get()
                ->keyBy(fn (object $row): string => $row->market.'|'.$row->source)
                ->map(fn (object $row): array => [
                    'total' => (int) $row->total,
                    'enabled' => (int) $row->enabled,
                    'failing' => (int) $row->failing,
                    'never_run' => (int) $row->never_run,
                    'last_run_at' => $row->last_run_at === null ? null : (string) $row->last_run_at,
                ])
                ->all();

            /*
             * Both catalogue numbers from one pass over `products`, and both
             * counting only what a visitor could actually be shown.
             *
             * `active` is not a detail here. Counting every `product_groups`
             * row reported 51 products for `en` on the day every one of its
             * offers was withdrawn — a market with nothing findable in it,
             * described by this page as stocked. A supply report that counts
             * rows a visitor cannot reach is measuring the wrong thing.
             *
             * Groups rather than offers is the headline for the same reason
             * invariant 3 exists: search, gift picks and guides all operate on
             * groups, so "12,000 offers across 300 products" is a thin market
             * wearing a big number. Both are shown, the offer count second.
             */
            $catalogue = DB::table('products')
                ->selectRaw('market')
                ->selectRaw('count(*) filter (where status = ?) as offers', [ProductStatus::Active->value])
                ->selectRaw('count(distinct group_id) filter (where status = ?) as groups', [ProductStatus::Active->value])
                ->groupBy('market')
                ->get();

            return [
                'feeds' => $feeds,
                'groups' => $catalogue->pluck('groups', 'market')->map(fn ($n): int => (int) $n)->all(),
                'offers' => $catalogue->pluck('offers', 'market')->map(fn ($n): int => (int) $n)->all(),
            ];
        });
    }
}
