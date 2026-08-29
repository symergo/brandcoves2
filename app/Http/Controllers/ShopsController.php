<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\ProductStatus;
use App\Enums\Source;
use App\Models\DailyPickSet;
use App\Models\Merchant;
use App\Services\Connectors\ConnectorRegistry;
use App\Services\Guides\CoveMarkup;
use App\Services\Seo\PageMeta;
use App\Support\CurrentMarket;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Shop Coves: the shops this market's prices are compared across.
 *
 * Every offer card on the site already names its shop, and nothing ever
 * answered the question that raises — *which* shops are these, and how many.
 * "We compare hundreds of shops" is the sort of claim a visitor has no way to
 * check, and an unverifiable claim is worth less than a shorter list they can
 * scroll.
 *
 * ## No counts, deliberately
 *
 * The same rule as the Discover hub and the front page: a page that totals the
 * catalogue is making the catalogue-counter mistake described in
 * homepage.md. "14,203 products" tells a shopper nothing about whether the one
 * thing they want is here, and it is the number most likely to be wrong.
 *
 * It is also what keeps this page cheap. Counting products per merchant means a
 * scan per shop over the largest table in the database, on a page whose whole
 * content is otherwise two small tables.
 *
 * ## Membership comes from the catalogue
 *
 * A shop is in this market when it has active offers here. That is what a
 * visitor means by "shops whose prices you compare", and it is one `EXISTS`
 * against `products.merchant_id`, which is indexed — it stops at the first row
 * rather than counting.
 *
 * It was written against `feeds` first, on the reasoning that the integration
 * is the truth and the catalogue merely reflects it. That was wrong twice over.
 * **`feeds.merchant_id` is null on every row in the database** — nothing in
 * ingestion ever sets it — so the join matched nothing and the page listed bol
 * and nothing else. And a live source has no feed at all, so half the answer
 * had to come from the connector registry regardless.
 *
 * The null FK is a real gap and is left as one: backfilling it means matching
 * feeds to merchants by label, which is a guess, and this page does not need it.
 */
class ShopsController extends Controller
{
    /**
     * How long a shop counts as new.
     *
     * Thirty days, because that is roughly the cadence at which advertisers are
     * onboarded here — long enough that the band is rarely empty, short enough
     * that "new" still means something. A permanent "new" badge is furniture.
     */
    private const NEW_FOR_DAYS = 30;

    /**
     * How many Shop Coves the page leads with.
     *
     * Twelve, matching every other Cove listing on the site. There will be far
     * fewer than that for a long time — there are six shops in the largest
     * market — so this is a ceiling rather than a target.
     */
    private const COVES = 12;

    public function __invoke(CurrentMarket $current, ConnectorRegistry $registry): Response
    {
        app(PageMeta::class)->set(
            title: __('site.shops.seo_title'),
            description: __('site.shops.seo_description'),
            canonical: url($current->url('shops')),
        );

        $market = $current->get();
        $live = $registry->liveSourcesFor($market);

        $shops = Merchant::query()
            ->where('enabled', true)
            ->where(function (Builder $q) use ($market, $live): void {
                $q->whereHas('products', fn (Builder $p) => $p
                    ->where('market', $market->value)
                    ->where('status', ProductStatus::Active->value));

                if ($live !== []) {
                    /*
                     * Live sources are listed whether or not they have rows
                     * here yet. One merchant row per source — bol is 'bol', not
                     * one row per bol seller — and its offers are fetched per
                     * request rather than ingested, so a market can compare bol
                     * prices while holding almost nothing of bol's in
                     * `products` (invariant 6 is the same story for Amazon).
                     */
                    $q->orWhereIn('source', array_map(fn (Source $s) => $s->value, $live));
                }
            })
            ->orderBy('name')
            ->get(['id', 'name', 'domain', 'logo_url', 'source', 'created_at']);

        $since = now()->subDays(self::NEW_FOR_DAYS);

        return Inertia::render('Shops/Index', [
            /*
             * The writing, above the directory.
             *
             * The directory answers "who do you compare"; these answer the half
             * of a buying decision a price comparison cannot — what a shop is
             * like to buy from, how its returns work, whether its delivery
             * promise is real. That is the reason to read this page rather than
             * scroll it, so it leads.
             *
             * Empty on a market where none has been written, and the band does
             * not render. Same rule as everywhere else here: an empty shelf is
             * worse than no shelf.
             */
            'coves' => DailyPickSet::query()
                ->forMarket($market)
                ->shops()
                ->published()
                ->orderByDesc('published_at')
                ->limit(self::COVES)
                ->get(['id', 'kind', 'slug', 'theme_title', 'theme_blurb'])
                ->map(fn (DailyPickSet $cove): array => [
                    'title' => $cove->theme_title,
                    // Tokens flattened to their labels, as every other card
                    // listing does it: a link inside a card whose whole surface
                    // is already a link is a target fighting its parent.
                    'intro' => app(CoveMarkup::class)->plain($cove->theme_blurb),
                    'url' => $current->url($cove->kind->path((string) $cove->slug)),
                ])
                ->all(),

            /*
             * The new arrivals, above the rest and repeated in it.
             *
             * Repeated on purpose: this is one alphabetical directory with a
             * spotlight over it, and a shop that vanishes from the A–Z because
             * it happens to be new is a shop somebody scrolling for it cannot
             * find. The badge on the card is what ties the two together.
             */
            'newShops' => $this->present($this->arrivals($shops, $since), $current, $since),

            'shops' => $this->present($shops, $current, $since),
        ]);
    }

    /**
     * The shops worth calling new.
     *
     * Empty when *every* shop is new, which is not a hypothetical: a market
     * onboarded in one sitting has its whole directory inside the window, and
     * the spotlight then reprints the page above itself under a heading that
     * promises something has changed. "New" is a comparison, and a comparison
     * against nothing says nothing.
     *
     * @param  Collection<int, Merchant>  $shops
     * @return Collection<int, Merchant>
     */
    private function arrivals(Collection $shops, Carbon $since): Collection
    {
        $new = $shops->filter(
            fn (Merchant $m) => $m->created_at !== null && $m->created_at->greaterThanOrEqualTo($since),
        );

        return $new->count() === $shops->count() ? $new->take(0) : $new;
    }

    /**
     * @param  Collection<int, Merchant>  $shops
     * @return list<array<string, mixed>>
     */
    private function present(Collection $shops, CurrentMarket $current, Carbon $since): array
    {
        return $shops->map(fn (Merchant $shop) => [
            'id' => $shop->id,
            'name' => $shop->name,
            'domain' => $shop->domain,
            // Their own favicon, never the affiliate network's — showing Awin's
            // mark for Coolblue and Krefel alike makes the directory useless.
            'logo' => $shop->faviconUrl(),
            'isNew' => $shop->created_at !== null && $shop->created_at->greaterThanOrEqualTo($since),
            /*
             * Into search, filtered to this shop.
             *
             * `?merchant[]=` is a filter the search page already takes, and with
             * no term the stored query still runs — so this lands on that shop's
             * catalogue in this market rather than on a page that needs typing
             * into first. A shop page of its own, mirroring `/brand/{slug}`,
             * is the next step and needs a slug column merchants do not have.
             */
            'url' => $current->url('search').'?merchant%5B%5D='.$shop->id,
        ])->values()->all();
    }
}
