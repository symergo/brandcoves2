<?php

declare(strict_types=1);

namespace App\Services\Editorial;

use App\Enums\Market;
use App\Enums\ProductStatus;
use App\Enums\Source;
use App\Models\ProductGroup;
use App\Services\Search\SearchQuery;
use App\Services\Search\SearchService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * The catalogue, as an author needs to see it.
 *
 * Deliberately not SearchService. That one exists to answer a shopper: it fans
 * out to live connectors, caches, ranks by commercial intent and returns offers.
 * A writer needs the opposite — the stable, already-ingested groups, with the
 * facts that decide whether a product can appear in a given piece of writing.
 *
 * ## Why the API cannot let an author name a product freely
 *
 * Everything written through the editorial API links by ID, never by name, and
 * the IDs have to come from somewhere. If the only way to get one is to look it
 * up here, then every product mentioned in every article provably exists, is in
 * the right market, and was in stock at the moment it was chosen. A writer that
 * cannot enumerate the catalogue will confidently invent it.
 *
 * Groups, not products, throughout — offer comparison is the point of the site
 * and a group is the thing that has offers.
 */
class ProductLookup
{
    /** A page of results. Enough to choose from, small enough to read. */
    private const PER_PAGE = 24;

    public function __construct(private readonly SearchService $search) {}

    /**
     * Presentable groups in a market, optionally matching a phrase.
     *
     * @return Collection<int, ProductGroup>
     */
    public function search(
        Market $market,
        ?string $term = null,
        ?string $category = null,
        ?string $brand = null,
        ?int $minPrice = null,
        ?int $maxPrice = null,
        int $limit = self::PER_PAGE,
        bool $includeLive = false,
    ): Collection {
        if ($includeLive && filled($term)) {
            $this->pullLive($market, (string) $term);
        }

        $query = ProductGroup::query()
            ->forMarket($market)
            // In stock, priced and pictured. An author choosing a product with
            // no image is choosing a card that renders as broken.
            ->presentable();

        if ($term !== null && trim($term) !== '') {
            /*
             * Full-text against the offers' search_vector, exactly as the
             * edition builder does it.
             *
             * `websearch_to_tsquery` rather than `plainto_` so an author can
             * write `koptelefoon -bluetooth` and have it mean something, and
             * `bc_text_config` so the stemming matches the market's language —
             * a Dutch query stemmed as English finds nothing.
             */
            $query->whereExists(fn ($sub) => $sub
                ->select(DB::raw(1))
                ->from('products')
                ->whereColumn('products.group_id', 'product_groups.id')
                ->where('products.status', ProductStatus::Active->value)
                ->whereRaw(
                    // Bound, not read off the row. See
                    // TopicMiner::availableProducts — this is the difference
                    // between the full-text index and a sequential scan.
                    'products.search_vector @@ websearch_to_tsquery(bc_text_config(?), ?)',
                    [$market->value, trim($term)],
                ));
        }

        if ($category !== null) {
            $query->where('category', $category);
        }

        if ($brand !== null) {
            // ilike, because feeds disagree about capitalisation and an author
            // typing "sony" meaning Sony is not a mistake worth punishing.
            $query->where('brand', 'ilike', $brand);
        }

        if ($minPrice !== null) {
            $query->where('min_price', '>=', $minPrice);
        }

        if ($maxPrice !== null) {
            $query->where('min_price', '<=', $maxPrice);
        }

        return $query
            /*
             * Surprise first when nothing was asked for.
             *
             * The Cove is a column about odd finds, so "show me the catalogue"
             * should open on the odd end of it rather than on whichever rows
             * happen to have the lowest ids.
             */
            ->orderByDesc('surprise_score')
            ->orderBy('id')
            ->limit(min($limit, 100))
            ->get();
    }

    /**
     * Ask the live sources, so their offers exist before the query runs.
     *
     * ## Why this delegates to SearchService rather than doing it here
     *
     * The catalogue is built from Awin feeds. bol is a *live* connector, queried
     * per search and never crawled — so an author restricted to stored rows was
     * silently writing "the four best kitchen scales **that happen to be in an
     * Awin feed**", a limit the article would never admit to.
     *
     * SearchService already solves exactly this for shoppers: it pulls the live
     * offers, upserts them through the ordinary {@see OfferUpserter}, and groups
     * the new arrivals narrowly so an incoming bol offer joins an existing card
     * as another shop in the same request. Which means after this call a bol
     * product **is** an ordinary `product_groups` row — same id, same offer
     * comparison, same `/go/` affiliate redirect as anything a feed brought in.
     *
     * Reused rather than reimplemented for the reason it matters most: a second
     * path into the catalogue would be a second implementation of the identity
     * rules, and that is precisely where a wrong merge would come from.
     *
     * Best-effort. A live source that is down, cooling off after a 429 or simply
     * slow must cost the author fewer results, never a failed call — the stored
     * catalogue is still there and is still the bulk of the answer.
     */
    private function pullLive(Market $market, string $term): void
    {
        try {
            $this->search->search(new SearchQuery(
                market: $market,
                term: $term,
                // An author choosing what to write about wants to see what
                // exists, not only what is on offer this week — the shopper
                // default would hide every full-price product.
                discountedOnly: false,
            ));
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * Everything an author needs to decide whether to use this product.
     *
     * The compliance flags are the load-bearing part. `priceGuessEligible`
     * answers "can this be the subject of the daily price game" — a source that
     * requires a live re-fetch cannot, because the answer would be frozen for
     * twelve hours and might no longer be the answer by the reveal. Rather than
     * let an author discover that by having their pick silently skipped at
     * build time, it is a field.
     *
     * @return array<string, mixed>
     */
    public function describe(ProductGroup $group): array
    {
        return [
            'id' => $group->id,
            'market' => $group->market->value,
            'title' => $group->title,
            'brand' => $group->brand,
            'category' => $group->category,
            // Integer cents everywhere, never a float and never a formatted
            // string: these are compared and aggregated, and a formatted price
            // that gets parsed back is how currency bugs start.
            'minPriceCents' => $group->min_price,
            'medianPriceCents' => $group->median_price,
            'discountPercent' => $group->discountPercent(),
            'merchantCount' => $group->merchant_count,
            'inStock' => (bool) $group->in_stock,
            'imageUrl' => $group->image_url,
            'surpriseScore' => $group->surprise_score,
            'url' => '/'.$group->market->value.'/p/'.$group->id.'/'.$group->slug,
            'priceGuessEligible' => $this->priceGuessEligible($group),

            /*
             * Which programmes back this product.
             *
             * An author writing "also on bol" needs to know it is true, and an
             * author writing about an Amazon-only product needs to know the
             * rules are different there — direct anchor, no email, no price
             * history. Stating the sources is cheaper than every caller
             * inferring them from a price.
             */
            'sources' => $this->sources($group),
        ];
    }

    /**
     * The distinct sources with an active offer on this group.
     *
     * @return list<string>
     */
    private function sources(ProductGroup $group): array
    {
        return DB::table('products')
            ->where('group_id', $group->id)
            ->where('status', ProductStatus::Active->value)
            ->distinct()
            ->orderBy('source')
            ->pluck('source')
            ->all();
    }

    /**
     * Whether this group's cheapest offer comes from a source that permits its
     * price to be stored and scored against later.
     *
     * Mirrors EditionBuilder::challenge() rather than reimplementing the rule,
     * and the €10 floor is the same one: below that the guess bands are so wide
     * the game is not interesting.
     *
     * See docs/features/amazon-compliance.md.
     */
    public function priceGuessEligible(ProductGroup $group): bool
    {
        if ($group->min_price === null || $group->min_price < 1000) {
            return false;
        }

        $storable = array_values(array_filter(
            Source::values(),
            fn (string $s) => Source::from($s)->allowsPriceTracking(),
        ));

        return DB::table('products')
            ->where('group_id', $group->id)
            ->where('status', ProductStatus::Active->value)
            ->whereIn('source', $storable)
            ->where('price', $group->min_price)
            ->exists();
    }

    /**
     * Validate a list of group ids against a market.
     *
     * Returns the ids that are *not* usable, so a caller can reject the whole
     * write and say which ones were wrong. Partial acceptance would be worse:
     * an article whose third pick silently vanished is an article with a
     * dangling sentence.
     *
     * @param  list<int>  $ids
     * @return list<int>
     */
    public function rejectUnusable(Market $market, array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $usable = ProductGroup::query()
            ->forMarket($market)
            ->presentable()
            ->whereIn('id', $ids)
            ->pluck('id')
            ->all();

        return array_values(array_diff($ids, $usable));
    }
}
