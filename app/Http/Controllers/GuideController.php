<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\CoveKind;
use App\Enums\CoveScene;
use App\Enums\PublishStatus;
use App\Models\DailyPick;
use App\Models\DailyPickSet;
use App\Models\Merchant;
use App\Services\Cove\CoveProse;
use App\Services\Cove\CoveRail;
use App\Services\Cove\EntityLinks;
use App\Services\Cove\EntityRails;
use App\Services\Cove\SavedCoves;
use App\Services\Guides\CoveMarkup;
use App\Services\Pages\Context\EntityCoveContext;
use App\Services\Pages\PageCopy;
use App\Services\Pages\Regions\EntityCoveRegions;
use App\Services\Seo\PageMeta;
use App\Services\Seo\SocialCard;
use App\Services\Seo\StructuredData;
use App\Services\Shops\ShopDirectory;
use App\Support\CurrentMarket;
use App\Support\PreviewAccess;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Buying guides, seasonal guides and advice articles.
 *
 * The evergreen half of the Daily Cove. A guide gets its audience on the day its
 * edition drops and its traffic for years afterwards, which is why the two are
 * built together and published on separate clocks.
 *
 * Since the fold these are rows in `daily_pick_sets` like every other Cove,
 * selected by `scopeArticles()`. The URLs did not change and must not: this
 * space is indexed, linked from `[[guide:slug]]` tokens across the site, and the
 * target of the `magazine`/`articles` legacy redirects.
 */
class GuideController extends Controller
{
    public function index(CurrentMarket $current): Response
    {
        $guides = DailyPickSet::query()
            ->where('market', $current->value())
            ->articles()
            ->where('status', PublishStatus::Published->value)
            ->orderByDesc('published_at')
            ->limit(60)
            /*
             * Only what a card shows. Every column came back before, the
             * article's body, FAQ and stored prose included, for sixty cards
             * that print a title and a line.
             */
            ->get(['id', 'kind', 'slug', 'theme_title', 'theme_blurb', 'scene', 'published_at'])
            ->map(fn (DailyPickSet $guide) => [
                'title' => $guide->theme_title,
                // A card blurb, not an article: tokens flattened to their
                // labels rather than resolved. A link inside a card whose whole
                // surface is already a link is a target fighting its parent.
                'intro' => app(CoveMarkup::class)->plain($guide->theme_blurb),
                'kind' => $guide->kind->value,
                /*
                 * The drawing, and never null.
                 *
                 * This shelf is a shelf of writing, so nothing on it has a
                 * photograph — it rendered as a grid of identical text
                 * rectangles until each article named its own subject. A guide
                 * that names none still gets `article`, because half a grid of
                 * drawings and half a grid of blanks reads as images that
                 * failed to load rather than as a deliberate difference.
                 */
                'scene' => ($guide->scene ?? CoveScene::defaultFor($guide->kind))->value,
                'url' => $current->url($guide->kind->path((string) $guide->slug, $current->get())),
                'publishedAt' => $guide->published_at?->toDateString(),
            ]);

        app(PageMeta::class)->set(
            title: __('site.guides.seo_title'),
            description: __('site.guides.seo_description'),
            canonical: url($current->url('guides')),
        );

        return Inertia::render('Guides/Index', ['guides' => $guides]);
    }

    public function show(Request $request, CurrentMarket $current, string $market, string $slug): Response
    {
        return $this->render($request, $current, $slug, fn (Builder $q) => $q->articles());
    }

    /**
     * A Shop Cove: the same page, read from a different URL space.
     *
     * `/shops/{slug}` rather than `/guides/{slug}`, because a piece about what
     * a shop is like to buy from belongs above the directory of shops rather
     * than in the archive of buying guides. Everything below this line is
     * identical — the allowlist, the prose resolution, the FAQ, the preview
     * gate and the structured data are properties of *an article*, and a second
     * copy of them would drift within a month.
     */
    public function shop(Request $request, CurrentMarket $current, string $market, string $slug): Response
    {
        return $this->render($request, $current, $slug, fn (Builder $q) => $q->shops());
    }

    /**
     * @param  Closure(Builder<DailyPickSet>): mixed  $kinds
     */
    private function render(Request $request, CurrentMarket $current, string $slug, Closure $kinds): Response
    {
        // An admin, or somebody holding a signed preview link, reads the draft.
        $preview = PreviewAccess::allowed($request);

        $guide = DailyPickSet::query()
            ->where('market', $current->value())
            ->tap($kinds)
            ->where('slug', $slug)
            ->unless($preview, fn ($query) => $query->where('status', PublishStatus::Published->value))
            ->with(['picks.group'])
            ->first();

        if ($guide === null) {
            throw new NotFoundHttpException;
        }

        /*
         * A Shop Cove's shop and link list, resolved once for the whole page.
         *
         * Each was looked up where it was used: the shop four times (the
         * allowlist, the rails, the page, the count) and the shop's product
         * count twice. On production that was the directory query four times
         * and a count over the shop's offers twice, on one view.
         *
         * The link list is the one stored when the Cove was built, so the
         * 4.4 s "group every offer by category" query does not run here at all.
         * See App\Services\Cove\EntityLinks.
         *
         * The shop is matched through `ShopDirectory`, which owns both the
         * membership question and the slug rule: a Shop Cove's slug is derived
         * from `merchants.domain`, and that derivation is the only thing that
         * connects this page to a merchant at all.
         *
         * Null and empty for every other kind.
         */
        $isShop = $guide->kind === CoveKind::Shop;
        $shop = $isShop ? app(ShopDirectory::class)->shopFor($current->get(), (string) $guide->slug) : null;
        $shopLinks = $isShop ? app(EntityLinks::class)->forShopCove($guide, $shop) : [];

        /*
         * The prose, rendered when the Cove was built: the intro and the
         * article as blocks, each product's copy, the FAQ, and their plain
         * versions for the meta description and the structured data.
         *
         * What the prose may link to is this article's own items, every other
         * published guide in this market, the queries that justified it and,
         * for a Shop Cove, the categories that shop sells in. That second half
         * is what makes an advice piece worth writing at all: "how to spot a
         * paid review" earns its place by pointing at the guide for the thing
         * the reader was about to buy. Working it out is two queries and a
         * regex pass over every paragraph, so it happens at build, not per
         * view: see App\Services\Cove\CoveProse.
         *
         * The intro and the body are one document, so reading order decides
         * which paragraph owns a card: a product introduced in the intro is
         * not shown a second time halfway down the article.
         */
        $prose = app(CoveProse::class)->for($guide);

        $items = $guide->picks
            /*
             * Catalogue products only.
             *
             * An article may now carry a pick that is a *decision* rather than a
             * row — an Amazon ASIN, whose title and price we may not store and
             * must re-fetch live. Guides never had one, because the old
             * `guide_items` table could not express it. Dropped rather than
             * half-rendered until the article page can fetch one; invariant 6
             * says a failed fetch hides the item, and hiding it here is the same
             * answer arrived at earlier.
             */
            ->filter(fn (DailyPick $pick) => $pick->group !== null)
            ->map(fn (DailyPick $pick) => [
                'rank' => $pick->rank,
                'groupId' => $pick->group->id,
                'title' => $pick->group->displayTitle(),
                'brand' => $pick->group->brand,
                'image' => $pick->group->image_url,
                // Live from the group, never from the row. A price written into
                // editorial copy is wrong within a week and the copy is what a
                // reader trusts.
                'price' => $pick->group->min_price,
                'merchantCount' => $pick->group->merchant_count,
                'inStock' => $pick->group->in_stock,
                // The copy as rendered with the prose; resolved there rather
                // than at write time, so a guide that later unpublishes
                // degrades its links to plain text at the next build.
                'copy' => $prose['copy'][$pick->id] ?? [],
                'verdict' => $pick->verdict,
                'unavailable' => $pick->unavailable || ! $pick->group->in_stock,
                'url' => $current->url("p/{$pick->group->id}/{$pick->group->slug}"),
            ])
            ->values();

        // A draft read through a preview link is the real page at the real
        // URL, and must say `noindex` there. The flag was computed above and
        // never handed on, so `seo()`'s own default of `false` always won.
        $this->seo($guide, $items->all(), $current, $prose['plain'], $preview && ! $guide->isPublished());

        /*
         * A Shop Cove's product rails.
         *
         * The half that was missing: the page described a shop and showed none
         * of what it sells. Live rather than frozen, because a page's products
         * and its prose move at different speeds — see
         * App\Services\Cove\EntityRails.
         *
         * Null for every other kind. A buying guide already carries its
         * shortlist, and a rail underneath one would be a second, unranked
         * answer to the question the article just answered.
         */
        $rails = $shop === null
            ? null
            : app(EntityRails::class)->forShop($shop, $current->get());

        /*
         * A Shop Cove is an entity page, not an article with a shortlist.
         *
         * Same shape as a written brand page and rendered by the same component:
         * the writing is the page, the shop's products sit beside it, and "see
         * all" leads to a search filtered to that shop — which is exactly where
         * the shops directory sends a shop nobody has written about. One
         * destination for "show me what they sell", reached two ways.
         *
         * Everything above this line still runs, because the piece is written,
         * linked and SEO'd the way every other Cove is. Only the layout forks.
         */
        if ($isShop) {
            return $this->entityPage($guide, $current, $prose, $rails, $shop, $shopLinks);
        }

        return Inertia::render('Guides/Show', [
            // Save into My Coves; see docs/features/saved-coves.md.
            'saveCove' => app(SavedCoves::class)->button($guide->id),
            // Renders a banner, and only ever true for somebody entitled to it.
            'preview' => $preview && $guide->status !== PublishStatus::Published,
            'rails' => $rails,
            'guide' => [
                'title' => $guide->theme_title,
                /*
                 * Decides whether the page expects a shortlist. An advice
                 * article with an empty <ol> would read as a broken buying guide
                 * rather than as a finished piece of writing.
                 *
                 * A seasonal Cove reports itself as `buying`: it is a buying
                 * guide in every respect the *page* cares about, and the season
                 * is a scheduling fact rather than a layout one. Keeping the two
                 * values the React page already knows about means the fold
                 * changed no component props.
                 */
                'kind' => $guide->kind->expectsShortlist() ? 'buying' : 'advice',
                // The same drawing the shelf card carried, so arriving on the
                // article confirms you opened the one you clicked. See the
                // index method for why it is never null.
                'scene' => ($guide->scene ?? CoveScene::defaultFor($guide->kind))->value,
                'intro' => $prose['intro'],
                'body' => $prose['body'],
                // Questions plain, answers with their links resolved: a link
                // in a heading is one a reader hits while scanning for the
                // question they have.
                'faq' => $prose['faq'],
                'updatedAt' => $guide->last_checked_at?->toDateString(),
                // Stated plainly. "We wrote this because 240 people searched for
                // it here" is both the honest reason and a fact no competitor
                // can copy.
                'searchVolume' => $guide->source_volume,
            ],
            /*
             * The whole shortlist, always — the page decides what to do with
             * it, not this.
             *
             * Two readers need every row. The `<ol>` renders whatever the prose
             * did not name, which it can only work out from the full set; and
             * the ItemList below is built from the full set too, because the
             * page ranks all seven products whether it showed a card inline or
             * in the list. Shrinking this to "the leftovers" would under-report
             * the page to a crawler to save the client one filter.
             */
            'items' => $items,

            /*
             * The other articles, and more products from this one's categories.
             *
             * This page had no onward navigation at all — no archive strip, no
             * "back to the shelf", nothing — which matters most here of the
             * three: an article is the Cove search actually lands people on,
             * and it was the one that told them least about what else is here.
             *
             * A Shop Cove renders this page too, and gets its own band: the
             * rail asks what kind this Cove is rather than which controller
             * method built it.
             */
            'rail' => app(CoveRail::class)->for($guide, $current),
        ]);
    }

    /**
     * The written shop page: the piece, with the shop's products beside it.
     *
     * @param  array<string, mixed>  $prose  from CoveProse
     * @param  array<string, mixed>|null  $rails
     * @param  list<string>  $categories  the shop's link list, as resolved in render()
     */
    private function entityPage(
        DailyPickSet $guide,
        CurrentMarket $current,
        array $prose,
        ?array $rails,
        ?Merchant $shop,
        array $categories,
    ): Response {
        $market = $current->get();

        /*
         * Rendered as one string, not as prose blocks (by CoveProse, at build).
         *
         * `ProseCards` splits a buying guide into blocks so a product card can
         * be dropped in beside the paragraph that argues for it. An entity Cove
         * carries no shortlist — its prose is about ranges, and the products are
         * a live rail — so there is never a card to place, and the block shape
         * would be structure with nothing to hold. Same call the brand side
         * makes, so both halves of one page component get one shape.
         */
        /*
         * Null where no count can be trusted: a live connector answers per
         * request, so the rows stored for it are not its range. The page then
         * offers "all offers" rather than a number that happens to be wrong.
         * Asked once; it was asked twice, for the copy and for the page.
         */
        $total = $shop === null ? null : app(ShopDirectory::class)->productCount($shop, $market);

        /*
         * A shop this market no longer compares.
         *
         * The Cove survives its shop: a merchant can be switched off or drop out
         * of a market long after somebody wrote about buying from them. There is
         * then no directory row, no rail and nowhere for "see all" to lead, so
         * the page renders without them rather than pointing at an empty search.
         */
        $searchUrl = $shop === null
            ? $current->url('search')
            : $current->url('search').'?'.http_build_query(['merchant' => [$shop->id]]);

        $context = new EntityCoveContext(
            market: $market,
            items: [],
            total: $total ?? 0,
            page: EntityCoveRegions::SHOP,
            entity: $shop?->displayName() ?? (string) $guide->theme_title,
            slug: (string) $guide->slug,
            searchUrl: $searchUrl,
            categories: $categories,
        );

        return Inertia::render('Entity/Cove', [
            // Save into My Coves; see docs/features/saved-coves.md.
            'saveCove' => app(SavedCoves::class)->button($guide->id),
            'entity' => [
                'name' => $shop?->displayName() ?? (string) $guide->theme_title,
                'kind' => 'shop',
                'total' => $total,
                // Their own mark, never the affiliate network's — the same rule
                // the directory follows.
                'logo' => $shop?->faviconUrl(),
            ],
            'cove' => [
                'title' => $guide->theme_title,
                'intro' => $prose['entity']['intro'],
                /*
                 * Paragraph by paragraph, not one string.
                 *
                 * `render()` resolves tokens and leaves the text as it found
                 * it, so a piece written in three paragraphs arrived as one
                 * wall of prose - and nothing reported it, because the tokens
                 * all resolved. `paragraphs()` splits on blank lines first,
                 * which is what every other written page here already does.
                 *
                 * Found 2026-09-06 reading the first published Shop Cove.
                 */
                'body' => $prose['entity']['body'],
                'metaDescription' => $guide->meta_description
                    ?: $prose['plain']['blurb'],
            ],
            'rails' => $rails,
            'searchUrl' => $searchUrl,
            'copy' => app(PageCopy::class)->forPage($context),
        ]);
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @param  array{blurb: string, faq: list<array{q: string, a: string}>}  $plain  the prose without links, from CoveProse
     */
    private function seo(DailyPickSet $guide, array $items, CurrentMarket $current, array $plain, bool $preview = false): void
    {
        /*
         * The kind's own path, not `guides/` for everything.
         *
         * `shop()` renders `/shops/{slug}` through this same method, and the
         * URL was a literal `guides/{slug}` — so every Shop Cove's canonical
         * tag, breadcrumb and ItemList pointed at a `/guides/` address that
         * `show()` scopes to `articles()` and 404s. A canonical to a 404 is a
         * page telling the crawler to drop it.
         */
        $url = url($current->url($guide->kind->path((string) $guide->slug, $current->get())));
        $meta = app(PageMeta::class);

        $meta->set(
            title: $guide->theme_title,
            // Through plain(): the intro carries link tokens now, and a meta
            // description reading "see [[page:search]]" is what a searcher sees
            // in the result.
            description: $guide->meta_description ?? $plain['blurb'],
            // The card, not the first product's photograph: a guide is about all
            // seven, and leading with one of them misrepresents it.
            image: SocialCard::versioned(url($current->url("og/guide/{$guide->slug}.png"))),
            canonical: $url,
            /*
             * A draft is never indexed, whatever else the page would say.
             *
             * A preview is the real page at the real URL, which is exactly what
             * makes it useful and exactly what makes this necessary: without it
             * a crawler following a shared preview link would put unpublished
             * copy in the index, at the address the finished piece will use.
             */
            /*
             * An empty shortlist is a thin page — unless it was never meant to
             * have one.
             *
             * A buying guide whose products have all gone out of stock has
             * nothing left to say and should not be indexed. An advice article
             * has no products by design and is the most indexable thing on the
             * site, so applying the same rule would `noindex` exactly the pages
             * written to rank — and a Shop Cove is the same case, which is why
             * this asks `expectsShortlist()` rather than naming Advice.
             */
            // A preview is private. Everything published is indexable, an
            // empty shortlist included (owner's decision, 2026-09-12).
            robots: $preview ? 'noindex, nofollow' : null,
        );

        // An ItemList of nothing asserts that this page ranks nothing, which is
        // worse than staying quiet — so a kind with no shortlist emits no
        // ItemList at all rather than an empty one.
        if ($items !== []) {
            $meta->addJsonLd(StructuredData::itemList(
                array_map(fn (array $item) => [
                    'name' => $item['title'],
                    'url' => url($item['url']),
                    'image' => $item['image'],
                ], $items),
                $guide->theme_title,
                $url,
            ));
        }

        if (is_array($guide->faq) && $guide->faq !== []) {
            // Plain text: a crawler reads an acceptedAnswer literally, so an
            // anchor tag there is markup in a field that expects prose.
            $meta->addJsonLd(StructuredData::faq($plain['faq']));
        }

        // The parent is the directory the kind is read under — a Shop Cove sits
        // in `/shops`, and a trail through `/guides` would name a section the
        // page is not in.
        $parent = $guide->kind === CoveKind::Shop
            ? ['name' => __('site.shops.title'), 'url' => url($current->url('shops'))]
            : ['name' => __('site.guides.title'), 'url' => url($current->url('guides'))];

        $meta->addJsonLd(StructuredData::breadcrumbs([
            ['name' => 'GiftCoves', 'url' => url($current->url())],
            $parent,
            ['name' => $guide->theme_title, 'url' => $url],
        ]));
    }
}
