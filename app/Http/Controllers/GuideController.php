<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\GuideKind;
use App\Enums\PublishStatus;
use App\Models\Guide;
use App\Models\GuideItem;
use App\Services\Editorial\Allowlist;
use App\Services\Guides\CoveMarkup;
use App\Services\Seo\PageMeta;
use App\Services\Seo\StructuredData;
use App\Support\CurrentMarket;
use App\Support\PreviewAccess;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Buying guides.
 *
 * The evergreen half of the Daily Cove. A guide gets its audience on the day its
 * edition drops and its traffic for years afterwards, which is why the two are
 * built together and published on separate clocks.
 */
class GuideController extends Controller
{
    public function index(CurrentMarket $current): Response
    {
        $guides = Guide::query()
            ->where('market', $current->value())
            ->where('status', PublishStatus::Published->value)
            ->orderByDesc('published_at')
            ->limit(60)
            ->get()
            ->map(fn (Guide $guide) => [
                'title' => $guide->title,
                // A card blurb, not an article: tokens flattened to their
                // labels rather than resolved. A link inside a card whose whole
                // surface is already a link is a target fighting its parent.
                'intro' => app(CoveMarkup::class)->plain($guide->intro),
                'kind' => $guide->kind->value,
                'url' => $current->url("guides/{$guide->slug}"),
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
        // An admin, or somebody holding a signed preview link, reads the draft.
        $preview = PreviewAccess::allowed($request);

        $guide = Guide::query()
            ->where('market', $current->value())
            ->where('slug', $slug)
            ->unless($preview, fn ($query) => $query->where('status', PublishStatus::Published->value))
            ->with(['items.group'])
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
            $guide->items->map(fn (GuideItem $item) => $item->group)->filter(),
            $current->get(),
            excludeGuideId: $guide->id,
            // The queries that justified this guide are also the searches it may
            // link to — which is the only thing an advice article has, having no
            // products to derive categories from.
            extraSearches: (array) $guide->source_queries,
        );

        $items = $guide->items
            ->filter(fn (GuideItem $item) => $item->group !== null)
            ->map(fn (GuideItem $item) => [
                'rank' => $item->rank,
                'groupId' => $item->group->id,
                'title' => $item->group->title,
                'brand' => $item->group->brand,
                'image' => $item->group->image_url,
                // Live from the group, never from the guide. A price written
                // into editorial copy is wrong within a week and the copy is
                // what a reader trusts.
                'price' => $item->group->min_price,
                'merchantCount' => $item->group->merchant_count,
                'inStock' => $item->group->in_stock,
                'copy' => $this->prose($item->editorial_copy, $current, $allowed),
                'verdict' => $item->verdict,
                'unavailable' => $item->unavailable || ! $item->group->in_stock,
                'url' => $current->url("p/{$item->group->id}/{$item->group->slug}"),
            ])
            ->values();

        $this->seo($guide, $items->all(), $current);

        return Inertia::render('Guides/Show', [
            // Renders a banner, and only ever true for somebody entitled to it.
            'preview' => $preview && $guide->status !== PublishStatus::Published,
            'guide' => [
                'title' => $guide->title,
                // Decides whether the page expects a shortlist. An advice
                // article with an empty <ol> would read as a broken buying
                // guide rather than as a finished piece of writing.
                'kind' => $guide->kind->value,
                'intro' => $this->prose($guide->intro, $current, $allowed),
                'body' => $this->prose($guide->body_md, $current, $allowed),
                'faq' => $this->faq($guide, $current, $allowed),
                'updatedAt' => $guide->last_checked_at?->toDateString(),
                // Stated plainly. "We wrote this because 240 people searched for
                // it here" is both the honest reason and a fact no competitor
                // can copy.
                'searchVolume' => $guide->source_volume,
            ],
            'items' => $items,
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
    private function faq(Guide $guide, CurrentMarket $current, array $allowed): ?array
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
    private function seo(Guide $guide, array $items, CurrentMarket $current, bool $preview = false): void
    {
        $url = url($current->url("guides/{$guide->slug}"));
        $meta = app(PageMeta::class);

        $markup = app(CoveMarkup::class);

        $meta->set(
            title: $guide->title,
            // Through plain(): the intro carries link tokens now, and a meta
            // description reading "see [[page:search]]" is what a searcher sees
            // in the result.
            description: $guide->meta_description ?? $markup->plain($guide->intro),
            // The card, not the first product's photograph: a guide is about all
            // seven, and leading with one of them misrepresents it.
            image: url($current->url("og/guide/{$guide->slug}.png")),
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
             * written to rank.
             */
            robots: $preview
                ? 'noindex, nofollow'
                : ($items === [] && $guide->kind === GuideKind::Buying ? 'noindex, follow' : null),
        );

        // An ItemList of nothing asserts that this page ranks nothing, which is
        // worse than staying quiet — so an advice article emits no ItemList at
        // all rather than an empty one.
        if ($items !== []) {
            $meta->addJsonLd(StructuredData::itemList(
                array_map(fn (array $item) => [
                    'name' => $item['title'],
                    'url' => url($item['url']),
                    'image' => $item['image'],
                ], $items),
                $guide->title,
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
            ['name' => $guide->title, 'url' => $url],
        ]));
    }
}
