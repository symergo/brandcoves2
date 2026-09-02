<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\PublishStatus;
use App\Models\DailyPick;
use App\Models\DailyPickSet;
use App\Services\Cove\CoveRail;
use App\Services\Editorial\Allowlist;
use App\Services\Editorial\ProseCards;
use App\Services\Guides\CoveMarkup;
use App\Services\Seo\PageMeta;
use App\Services\Seo\SocialCard;
use App\Services\Seo\StructuredData;
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
            ->get()
            ->map(fn (DailyPickSet $guide) => [
                'title' => $guide->theme_title,
                // A card blurb, not an article: tokens flattened to their
                // labels rather than resolved. A link inside a card whose whole
                // surface is already a link is a target fighting its parent.
                'intro' => app(CoveMarkup::class)->plain($guide->theme_blurb),
                'kind' => $guide->kind->value,
                'url' => $current->url($guide->kind->path((string) $guide->slug)),
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
         * What this article's prose may link to.
         *
         * Its own items, plus every other published guide in this market. That
         * second half is what makes an advice piece worth writing at all: "how
         * to spot a paid review" earns its place by pointing at the guide for
         * the thing the reader was about to buy, and an article that links
         * nowhere is a leaf in the crawl graph too.
         *
         * Resolved once, before the items are mapped, because every sentence on
         * the page shares it — and one of its halves is a query.
         */
        $allowed = app(Allowlist::class)->full(
            $guide->picks->map(fn (DailyPick $pick) => $pick->group)->filter(),
            $current->get(),
            excludeGuideId: $guide->id,
            // The queries that justified this guide are also the searches it may
            // link to — which is the only thing an advice article has, having no
            // products to derive categories from.
            extraSearches: (array) $guide->source_queries,
        );

        /*
         * The article, paragraph by paragraph, each carrying the products it
         * names — the same pairing the Daily Cove has had since it stopped
         * being prose-then-grid.
         *
         * Built before the items, and from one document, so that reading order
         * decides which paragraph owns a card: a product introduced in the
         * intro is not shown a second time halfway down the article.
         */
        $prose = new ProseCards(app(CoveMarkup::class), $current->get(), $allowed);

        $intro = $prose->blocks($guide->theme_blurb);
        $body = $prose->blocks($guide->body);

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
                'title' => $pick->group->title,
                'brand' => $pick->group->brand,
                'image' => $pick->group->image_url,
                // Live from the group, never from the row. A price written into
                // editorial copy is wrong within a week and the copy is what a
                // reader trusts.
                'price' => $pick->group->min_price,
                'merchantCount' => $pick->group->merchant_count,
                'inStock' => $pick->group->in_stock,
                'copy' => $this->prose($pick->blurb, $current, $allowed),
                'verdict' => $pick->verdict,
                'unavailable' => $pick->unavailable || ! $pick->group->in_stock,
                'url' => $current->url("p/{$pick->group->id}/{$pick->group->slug}"),
            ])
            ->values();

        $this->seo($guide, $items->all(), $current);

        return Inertia::render('Guides/Show', [
            // Renders a banner, and only ever true for somebody entitled to it.
            'preview' => $preview && $guide->status !== PublishStatus::Published,
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
                'intro' => $intro,
                'body' => $body,
                'faq' => $this->faq($guide, $current, $allowed),
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
     * A block of copy, as paragraphs of safe HTML with its links resolved.
     *
     * Guides used to render as plain text and reject link tokens outright,
     * which made them dead ends: the one article type whose whole job is to
     * send a reader somewhere could not.
     *
     * Resolved here rather than at write time for the reason the Cove does the
     * same — the destinations follow the market the page is read in, and a
     * guide that later unpublishes degrades the links pointing at it to plain
     * text instead of leaving 404s baked into rows nobody revisits.
     *
     * @param  array<string, mixed>  $allowed
     * @return list<string>
     */
    private function prose(?string $text, CurrentMarket $current, array $allowed): array
    {
        if (blank($text)) {
            return [];
        }

        return app(CoveMarkup::class)->paragraphs((string) $text, $current->get(), $allowed)['html'];
    }

    /**
     * The FAQ, with links resolved in the answers.
     *
     * Questions stay plain: a link in a heading is a link a reader hits while
     * scanning for the question they have, and the answer underneath is where
     * it belongs. The JSON-LD is built from the unrendered pair, because
     * FAQPage answers are read literally by a crawler and an anchor tag in one
     * is markup in a place that expects text.
     *
     * @param  array<string, mixed>  $allowed
     * @return list<array{q: string, a: list<string>}>|null
     */
    private function faq(DailyPickSet $guide, CurrentMarket $current, array $allowed): ?array
    {
        if (! is_array($guide->faq) || $guide->faq === []) {
            return null;
        }

        return array_values(array_map(fn (array $pair) => [
            'q' => (string) ($pair['q'] ?? ''),
            'a' => $this->prose((string) ($pair['a'] ?? ''), $current, $allowed),
        ], $guide->faq));
    }

    /** @param list<array<string, mixed>> $items */
    private function seo(DailyPickSet $guide, array $items, CurrentMarket $current, bool $preview = false): void
    {
        $url = url($current->url("guides/{$guide->slug}"));
        $meta = app(PageMeta::class);

        $markup = app(CoveMarkup::class);

        $meta->set(
            title: $guide->theme_title,
            // Through plain(): the intro carries link tokens now, and a meta
            // description reading "see [[page:search]]" is what a searcher sees
            // in the result.
            description: $guide->meta_description ?? $markup->plain($guide->theme_blurb),
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
            robots: $preview
                ? 'noindex, nofollow'
                : ($items === [] && $guide->kind->expectsShortlist() ? 'noindex, follow' : null),
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
            $meta->addJsonLd(StructuredData::faq(array_map(fn (array $pair) => [
                'q' => $markup->plain($pair['q'] ?? ''),
                'a' => $markup->plain($pair['a'] ?? ''),
            ], $guide->faq)));
        }

        $meta->addJsonLd(StructuredData::breadcrumbs([
            ['name' => 'GiftCoves', 'url' => url($current->url())],
            ['name' => __('site.guides.title'), 'url' => url($current->url('guides'))],
            ['name' => $guide->theme_title, 'url' => $url],
        ]));
    }
}
