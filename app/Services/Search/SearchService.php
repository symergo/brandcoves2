<?php

declare(strict_types=1);

namespace App\Services\Search;

use App\Models\ProductGroup;
use App\Models\SearchLog;
use App\Services\Connectors\ConnectorRegistry;
use App\Services\Connectors\Offer;
use App\Services\Identity\Gtin;
use App\Services\Ingestion\OfferUpserter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Search over physical products, not offers.
 *
 * The whole point is that a shopper sees one card per product with every shop's
 * price beneath it — not eleven near-identical cards. So this queries
 * `product_groups` and joins offers for display, never the reverse.
 */
class SearchService
{
    public function __construct(
        private readonly ConnectorRegistry $registry,
        private readonly OfferUpserter $upserter,
    ) {}

    public function search(SearchQuery $query): SearchResult
    {
        // Live sources first: an offer that arrives now can join an existing
        // group and appear as an extra shop on a card in the same request.
        // Doing it after the SQL query would show a stale offer count.
        $liveCount = $query->hasTerm() ? $this->pullLiveResults($query) : 0;

        $groups = $this->storedQuery($query)->paginate(
            perPage: (int) config('brandcoves.search.per_page'),
            page: $query->page,
        );

        if ($query->hasTerm() && $query->page === 1) {
            // Logged after the count is known, because zero-result queries are
            // the most valuable rows in the table — they are content gaps.
            SearchLog::record($query->term, $query->market, $groups->total());
        }

        return new SearchResult(
            groups: $groups,
            query: $query,
            liveOffersAdded: $liveCount,
            facets: $this->facets($query),
        );
    }

    /** @return Builder<ProductGroup> */
    private function storedQuery(SearchQuery $query): Builder
    {
        $groups = ProductGroup::query()
            ->forMarket($query->market)
            ->whereNotNull('min_price')
            ->whereNotNull('image_url');

        if ($query->hasTerm()) {
            $this->applyTextMatch($groups, $query);
        }

        if ($query->inStockOnly) {
            $groups->where('in_stock', true);
        }

        if ($query->comparableOnly) {
            $groups->where('merchant_count', '>', 1);
        }

        if ($query->minPrice !== null) {
            $groups->where('min_price', '>=', $query->minPrice);
        }

        if ($query->maxPrice !== null) {
            $groups->where('min_price', '<=', $query->maxPrice);
        }

        if ($query->brands !== []) {
            $groups->whereIn('brand', $query->brands);
        }

        if ($query->merchantIds !== []) {
            // A group qualifies if ANY of its offers is from a selected shop —
            // the shopper is asking "who has this at Coolblue", not "which
            // products are Coolblue-exclusive".
            $groups->whereExists(fn ($q) => $q
                ->select(DB::raw(1))
                ->from('products')
                ->whereColumn('products.group_id', 'product_groups.id')
                ->whereIn('products.merchant_id', $query->merchantIds)
                ->where('products.status', 'active'));
        }

        if ($query->discountedOnly) {
            // Measured against our own 30-day median, never a merchant's "was"
            // price, which is frequently fiction.
            $groups->whereNotNull('median_price')
                ->whereColumn('min_price', '<', 'median_price');
        }

        return $this->applySort($groups, $query);
    }

    /**
     * Full text first, trigram as a safety net.
     *
     * The two fail differently and that is precisely why both are here: FTS
     * finds nothing for a misspelling, and trigram is noisy for a well-spelled
     * multi-word query.
     *
     * @param  Builder<ProductGroup>  $groups
     */
    private function applyTextMatch(Builder $groups, SearchQuery $query): void
    {
        $term = $query->term;

        // A scanned or pasted barcode is an exact identity, not a text query.
        $gtin = Gtin::normalise($term);
        if ($gtin !== null) {
            $groups->where('identity_key', $gtin);

            return;
        }

        $threshold = (float) config('brandcoves.search.trigram_threshold');

        $groups->where(function (Builder $q) use ($term, $threshold): void {
            // websearch_to_tsquery handles quoted phrases and OR from user
            // input without throwing on syntax a person would reasonably type —
            // plainto_ and to_tsquery both blow up on a stray colon or bracket.
            $q->whereExists(fn ($sub) => $sub
                ->select(DB::raw(1))
                ->from('products')
                ->whereColumn('products.group_id', 'product_groups.id')
                ->where('products.status', 'active')
                ->whereRaw('products.search_vector @@ websearch_to_tsquery(bc_text_config(products.market), ?)', [$term]))
                // `<%` is word_similarity, NOT `%`. `%` compares whole strings,
                // so a typo against a long product title scores below the 0.3
                // default and finds nothing at all. See docs/features/search.md.
                ->orWhereRaw('? <% product_groups.title', [$term])
                ->orWhereRaw('word_similarity(?, product_groups.title) >= ?', [$term, $threshold]);
        });
    }

    /** @param Builder<ProductGroup> $groups */
    private function applySort(Builder $groups, SearchQuery $query): Builder
    {
        return match ($query->sort) {
            'price_asc' => $groups->orderBy('min_price')->orderBy('id'),
            'price_desc' => $groups->orderByDesc('min_price')->orderBy('id'),
            'newest' => $groups->orderByDesc('first_seen_at')->orderBy('id'),
            'discount' => $groups
                ->whereNotNull('median_price')
                ->orderByRaw('(median_price - min_price)::float / NULLIF(median_price, 0) DESC NULLS LAST')
                ->orderBy('id'),
            default => $this->orderByRelevance($groups, $query),
        };
    }

    /** @param Builder<ProductGroup> $groups */
    private function orderByRelevance(Builder $groups, SearchQuery $query): Builder
    {
        if (! $query->hasTerm()) {
            // Browsing rather than searching: lead with things worth seeing.
            return $groups
                ->orderByDesc('merchant_count')
                ->orderByDesc('first_seen_at')
                ->orderBy('id');
        }

        // Rank on title similarity, then break ties toward products a shopper
        // can actually compare — a card showing three shops is more useful than
        // one showing a single price, at equal relevance.
        return $groups
            ->orderByRaw('word_similarity(?, product_groups.title) DESC', [$query->term])
            ->orderByDesc('merchant_count')
            ->orderBy('id');
    }

    /**
     * Query the live sources and fold their offers into the stored graph.
     *
     * This is what makes a bol result comparable rather than a separate list: an
     * incoming offer whose EAN matches an existing group becomes another shop
     * on the same card, immediately.
     */
    private function pullLiveResults(SearchQuery $query): int
    {
        $connectors = $this->registry->liveFor($query->market);
        if ($connectors === []) {
            return 0;
        }

        $offers = [];
        foreach ($connectors as $connector) {
            try {
                // Connectors degrade rather than throw, but a bug in one must
                // not take down search for the others either.
                $offers = [...$offers, ...$connector->search($query->term, $query->market)];
            } catch (Throwable $e) {
                report($e);
            }
        }

        if ($offers === []) {
            return 0;
        }

        $written = $this->upserter->upsert($offers)['written'];

        // Group only what just arrived. A full market regroup on every search
        // would be absurd; this touches the handful of affected rows.
        $this->groupIncoming($query, $offers);

        return $written;
    }

    /**
     * Attach freshly-arrived offers to their groups.
     *
     * Deliberately narrow: it creates groups for new identities and links the
     * incoming rows, but does not recompute market-wide aggregates. The nightly
     * grouper owns that. What it must do is make the new offer countable, which
     * is why offer_count and min_price are refreshed for the touched groups.
     *
     * @param  list<Offer>  $offers
     */
    private function groupIncoming(SearchQuery $query, array $offers): void
    {
        $externalIds = array_map(fn ($o) => $o->externalId, $offers);
        if ($externalIds === []) {
            return;
        }

        DB::statement(<<<'SQL'
            INSERT INTO product_groups (
                market, identity_key, identity_kind, title, slug, brand, image_url, category,
                first_seen_at, created_at, updated_at
            )
            SELECT DISTINCT ON (p.identity_key)
                p.market, p.identity_key, p.identity_kind, p.title,
                left(regexp_replace(lower(unaccent(p.title)), '[^a-z0-9]+', '-', 'g'), 80),
                p.brand, p.image_url, p.merchant_category, now(), now(), now()
            FROM products p
            WHERE p.market = ? AND p.external_id = ANY(?) AND p.identity_key IS NOT NULL
            ORDER BY p.identity_key, (p.image_url IS NOT NULL) DESC, p.price ASC NULLS LAST, p.id
            ON CONFLICT (market, identity_key) DO NOTHING
        SQL, [$query->market->value, '{'.implode(',', array_map(fn ($id) => '"'.$id.'"', $externalIds)).'}']);

        DB::statement(<<<'SQL'
            UPDATE products p SET group_id = g.id
            FROM product_groups g
            WHERE p.market = ? AND p.external_id = ANY(?)
              AND g.market = p.market AND g.identity_key = p.identity_key
              AND p.group_id IS DISTINCT FROM g.id
        SQL, [$query->market->value, '{'.implode(',', array_map(fn ($id) => '"'.$id.'"', $externalIds)).'}']);

        // Refresh counts for the affected groups only, so the card the shopper
        // is about to see says "2 shops" rather than "1".
        DB::statement(<<<'SQL'
            WITH touched AS (
                SELECT DISTINCT group_id FROM products
                WHERE market = ? AND external_id = ANY(?) AND group_id IS NOT NULL
            ),
            stats AS (
                SELECT p.group_id,
                       count(*) AS offer_count,
                       count(DISTINCT p.merchant_id) AS merchant_count,
                       min(p.price) FILTER (WHERE p.price IS NOT NULL) AS min_price,
                       max(p.price) FILTER (WHERE p.price IS NOT NULL) AS max_price,
                       bool_or(p.availability = 'in_stock') AS in_stock
                FROM products p
                JOIN touched t ON t.group_id = p.group_id
                WHERE p.status = 'active'
                GROUP BY p.group_id
            )
            UPDATE product_groups g
            SET offer_count = stats.offer_count,
                merchant_count = stats.merchant_count,
                min_price = stats.min_price,
                max_price = stats.max_price,
                in_stock = stats.in_stock,
                updated_at = now()
            FROM stats WHERE g.id = stats.group_id
        SQL, [$query->market->value, '{'.implode(',', array_map(fn ($id) => '"'.$id.'"', $externalIds)).'}']);
    }

    /**
     * Facet counts for the filter sidebar.
     *
     * Computed from the term and market only, ignoring the active filters, so
     * selecting a brand does not make every other brand vanish from the list —
     * a filter UI that erases its own options is unusable.
     *
     * @return array{brands: list<array{value: string, count: int}>, merchants: list<array{id: int, name: string, count: int}>, price: array{min: int|null, max: int|null}}
     */
    private function facets(SearchQuery $query): array
    {
        $base = ProductGroup::query()
            ->forMarket($query->market)
            ->whereNotNull('min_price')
            ->whereNotNull('image_url');

        if ($query->hasTerm()) {
            $this->applyTextMatch($base, $query);
        }
        if ($query->inStockOnly) {
            $base->where('in_stock', true);
        }

        $brands = (clone $base)
            ->select('brand', DB::raw('count(*) as total'))
            ->whereNotNull('brand')
            ->groupBy('brand')
            ->orderByDesc('total')
            ->limit(15)
            ->get()
            ->map(fn ($r) => ['value' => (string) $r->brand, 'count' => (int) $r->total])
            ->all();

        $merchants = DB::table('products')
            ->join('merchants', 'merchants.id', '=', 'products.merchant_id')
            ->joinSub((clone $base)->select('product_groups.id'), 'g', 'g.id', '=', 'products.group_id')
            ->where('products.status', 'active')
            ->select('merchants.id', 'merchants.name', DB::raw('count(DISTINCT products.group_id) as total'))
            ->groupBy('merchants.id', 'merchants.name')
            ->orderByDesc('total')
            ->limit(15)
            ->get()
            ->map(fn ($r) => ['id' => (int) $r->id, 'name' => (string) $r->name, 'count' => (int) $r->total])
            ->all();

        $price = (clone $base)->selectRaw('min(min_price) as lo, max(min_price) as hi')->first();

        return [
            'brands' => $brands,
            'merchants' => $merchants,
            'price' => ['min' => $price?->lo === null ? null : (int) $price->lo, 'max' => $price?->hi === null ? null : (int) $price->hi],
        ];
    }

    /**
     * Per-merchant lanes for the "by store" view.
     *
     * Capped per merchant, because one recently-ingested advertiser with a huge
     * feed would otherwise fill every lane and the view would show a single shop.
     *
     * @return array<string, list<ProductGroup>>
     */
    public function storeLanes(SearchQuery $query): array
    {
        $cap = (int) config('brandcoves.search.store_lane_cap');

        $ids = $this->storedQuery($query)->limit(300)->pluck('id');
        if ($ids->isEmpty()) {
            return [];
        }

        // A window function does the capping in SQL; pulling everything back
        // and slicing in PHP would mean fetching a whole feed to show 8 rows.
        $ranked = DB::table('products as p')
            ->join('merchants as m', 'm.id', '=', 'p.merchant_id')
            ->whereIn('p.group_id', $ids)
            ->where('p.status', 'active')
            ->select('p.group_id', 'm.name as merchant', 'p.price')
            ->selectRaw('row_number() OVER (PARTITION BY m.id ORDER BY p.price ASC NULLS LAST, p.id) as rn')
            ->get()
            ->filter(fn ($r) => $r->rn <= $cap)
            ->groupBy('merchant');

        $groups = ProductGroup::query()->whereIn('id', $ids)->get()->keyBy('id');

        return $ranked
            ->map(fn ($rows) => $rows
                ->map(fn ($r) => $groups->get($r->group_id))
                ->filter()
                ->values()
                ->all())
            ->filter(fn (array $lane) => $lane !== [])
            ->all();
    }
}
