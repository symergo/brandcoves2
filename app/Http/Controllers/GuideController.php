<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\PublishStatus;
use App\Models\Guide;
use App\Models\GuideItem;
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
                'intro' => $guide->intro,
                'url' => $current->url("guides/{$guide->slug}"),
                'publishedAt' => $guide->published_at?->toDateString(),
            ]);

        app(PageMeta::class)->set(
            title: __('site.guides.title'),
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
                'copy' => $item->editorial_copy,
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
                'intro' => $guide->intro,
                'body' => $guide->body_md,
                'faq' => $guide->faq,
                'updatedAt' => $guide->last_checked_at?->toDateString(),
                // Stated plainly. "We wrote this because 240 people searched for
                // it here" is both the honest reason and a fact no competitor
                // can copy.
                'searchVolume' => $guide->source_volume,
            ],
            'items' => $items,
        ]);
    }

    /** @param list<array<string, mixed>> $items */
    private function seo(Guide $guide, array $items, CurrentMarket $current, bool $preview = false): void
    {
        $url = url($current->url("guides/{$guide->slug}"));
        $meta = app(PageMeta::class);

        $meta->set(
            title: $guide->title,
            description: $guide->meta_description ?? $guide->intro,
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
            robots: $preview
                ? 'noindex, nofollow'
                : ($items === [] ? 'noindex, follow' : null),
        );

        $meta->addJsonLd(StructuredData::itemList(
            array_map(fn (array $item) => [
                'name' => $item['title'],
                'url' => url($item['url']),
                'image' => $item['image'],
            ], $items),
            $guide->title,
            $url,
        ));

        if (is_array($guide->faq) && $guide->faq !== []) {
            $meta->addJsonLd(StructuredData::faq($guide->faq));
        }

        $meta->addJsonLd(StructuredData::breadcrumbs([
            ['name' => 'Brandcoves', 'url' => url($current->url())],
            ['name' => __('site.guides.title'), 'url' => url($current->url('guides'))],
            ['name' => $guide->title, 'url' => $url],
        ]));
    }
}
