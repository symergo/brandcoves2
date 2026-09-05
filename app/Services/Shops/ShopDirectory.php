<?php

declare(strict_types=1);

namespace App\Services\Shops;

use App\Enums\Market;
use App\Enums\ProductStatus;
use App\Models\Merchant;
use App\Services\Connectors\ConnectorRegistry;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
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
    public function __construct(private ConnectorRegistry $registry) {}

    /**
     * The shops this market compares prices across.
     *
     * @return Collection<int, Merchant>
     */
    public function in(Market $market, array $columns = ['id', 'name', 'domain', 'source']): Collection
    {
        $live = $this->registry->liveSourcesFor($market);

        return Merchant::query()
            ->where('enabled', true)
            ->whereNotNull('domain')
            ->where(function (Builder $q) use ($market, $live): void {
                $q->whereHas('products', fn (Builder $p) => $p
                    ->where('market', $market->value)
                    ->where('status', ProductStatus::Active->value));

                if ($live !== []) {
                    $q->orWhereIn('source', array_map(fn ($s) => $s->value, $live));
                }
            })
            ->orderBy('name')
            ->get($columns);
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
