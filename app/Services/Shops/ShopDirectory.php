<?php

declare(strict_types=1);

namespace App\Services\Shops;

use App\Enums\Market;
use App\Enums\ProductStatus;
use App\Models\Merchant;
use App\Models\Product;
use App\Services\Connectors\ConnectorRegistry;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Which shops this market compares, and what a Shop Cove about one is called.
 *
 * Two questions with one answer each, and both were answered in more than one
 * place. `ShopsController` and `bc:seed-shop-coves` each carried their own copy
 * of the membership query; the slug rule lived only inside the seeder's loop.
 * `SeedShopCovesCommand` said the day a third caller appeared was the day this
 * earned a home of its own, and validating a hand-written Shop plan's slug is
 * that caller.
 *
 * ## Membership: the catalogue, not the feeds
 *
 * A shop is in a market when it has **active offers there**, or is a **live
 * source** whose connector supports the market. Written against `feeds` first,
 * which was wrong twice: `feeds.merchant_id` is null on every row in the
 * database, so the join matched almost nothing; and a live source has no feed at
 * all, because its offers are fetched per request rather than ingested.
 *
 * `liveSourcesFor()` rather than `liveFor()`, deliberately: that one drops a
 * source backing off after a 429 so a *request* degrades gracefully, and a
 * directory that loses bol because bol is briefly refusing would tell a visitor
 * we do not carry it.
 */
final readonly class ShopDirectory
{
    /**
     * An hour, for the membership list and for a shop's product count.
     *
     * Membership changes when a feed is onboarded or a shop is switched off,
     * which is days apart; the query behind it is an `EXISTS` over `products`
     * per merchant, and it ran up to four times on one shop page. An hour late
     * is the worst a new shop can be, and the directory is not where a shop is
     * announced.
     */
    private const TTL = 3600;

    public function __construct(private ConnectorRegistry $registry) {}

    /**
     * The shops this market compares prices across, A to Z.
     *
     * `$requireDomain` is false for the two listings (`/shops` and the shop
     * band on `/coves`), which always listed a shop with no domain; true, the
     * default, wherever a slug is derived from the domain and a shop without
     * one could never be named.
     *
     * @return Collection<int, Merchant>
     */
    public function in(Market $market, bool $requireDomain = true): Collection
    {
        $shops = $this->members($market);

        return $requireDomain
            ? $shops->filter(fn (Merchant $shop) => $shop->domain !== null)->values()
            : $shops;
    }

    /**
     * Every enabled shop with active offers here or a live source serving here.
     *
     * Cached as a whole per market, with every column any caller reads, so the
     * directory, the shop page, the `/coves` band and the seeder share one
     * query an hour instead of each running its own copy. Two of them had one
     * until 2026-09-27, and the copies already differed on whether a shop
     * needs a domain, which is what `$requireDomain` keeps.
     *
     * @return Collection<int, Merchant>
     */
    private function members(Market $market): Collection
    {
        return Cache::remember("bc:shops:{$market->value}", self::TTL, function () use ($market): Collection {
            $live = $this->registry->liveSourcesFor($market);

            return Merchant::query()
                ->where('enabled', true)
                ->where(function (Builder $q) use ($market, $live): void {
                    $q->whereHas('products', fn (Builder $p) => $p
                        ->where('market', $market->value)
                        ->where('status', ProductStatus::Active->value));

                    if ($live !== []) {
                        /*
                         * Live sources are listed whether or not they have rows
                         * here yet. One merchant row per source (bol is 'bol',
                         * not one row per bol seller), and its offers are
                         * fetched per request rather than ingested, so a market
                         * can compare bol prices while holding almost nothing
                         * of bol's in `products`.
                         */
                        $q->orWhereIn('source', array_map(fn ($s) => $s->value, $live));
                    }
                })
                ->orderBy('name')
                ->get(['id', 'name', 'domain', 'logo_url', 'source', 'created_at']);
        });
    }

    /**
     * Are this shop's offers fetched at render rather than stored?
     *
     * The question decides whether anything about this shop can be counted. A
     * live connector answers per request, so what is in `products` for it is
     * whatever happened to be written down, not the shop's range - see the note
     * in `ShopsController` about comparing bol's prices while holding almost
     * nothing of bol's.
     */
    public function servesLive(Merchant $shop, Market $market): bool
    {
        return in_array(
            $shop->source,
            $this->registry->liveSourcesFor($market),
            strict: false,
        );
    }

    /**
     * How many products this shop has in this market.
     *
     * For the "see all N products" link on a written shop page, so the number a
     * reader is offered is the number the search will show them. Counts
     * **groups**, not offer rows — invariant 3: one row is one merchant selling
     * one thing, and a shop with three sizes of the same kettle has one product
     * on the shelf, not three.
     *
     * **Null for a live source such as bol**, whose offers are fetched per
     * request rather than stored (invariant 6 is the same story for Amazon).
     * Rows exist for one in `products` - enough to say "we compare this shop"
     * and nowhere near its range - so counting them produces a confident number
     * that is simply wrong. Null means "no honest count", and the page says
     * "all offers" instead of inventing one.
     */
    public function productCount(Merchant $shop, Market $market): ?int
    {
        if ($this->servesLive($shop, $market)) {
            return null;
        }

        // Cached for the hour the membership list is: a "see all N products"
        // link that is an hour behind is still the number the search shows,
        // give or take a feed run, and the count reads every offer of the shop.
        return (int) Cache::remember(
            "bc:shops:{$market->value}:{$shop->id}:count",
            self::TTL,
            fn (): int => (int) Product::query()
                ->where('merchant_id', $shop->id)
                ->where('market', $market->value)
                ->where('status', ProductStatus::Active->value)
                ->whereNotNull('group_id')
                ->distinct()
                ->count('group_id'),
        );
    }

    /**
     * The slug a Shop Cove about this shop is read at.
     *
     * **Dots become separators rather than disappearing.** `Str::slug('bol.com')`
     * is `bolcom`, which reads as a typo in a URL and in a `[[guide:…]]` token.
     * So: `bol-com`, `coolblue-be`, `shop-action-com`.
     *
     * Derived from the domain and not from the name, because an editor tidies
     * "Coolblue BE" to "Coolblue" and the page's address would move underneath
     * every link to it. It is also what makes the same shop pairable across
     * markets for hreflang: one shop, one slug, everywhere it trades.
     */
    public static function slugFor(Merchant $shop): string
    {
        return Str::slug(str_replace('.', '-', (string) $shop->domain));
    }

    /**
     * The shop a Shop Cove slug names in this market, if any.
     *
     * Used to refuse a plan whose slug names no shop we compare here. The page
     * sits above the directory of shops it is about, so a Cove about a shop
     * absent from that directory is an article about somebody we do not carry —
     * and nothing else would ever report it, because every other validation the
     * plan passes is about shape rather than about meaning.
     *
     * Compared on the derived slug rather than queried on the domain, so the one
     * rule in `slugFor()` decides both what a Cove is called and what counts as
     * naming it.
     */
    public function shopFor(Market $market, string $slug): ?Merchant
    {
        return $this->in($market)->first(fn (Merchant $shop) => self::slugFor($shop) === $slug);
    }
}
