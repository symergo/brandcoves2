<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\DailyPick;
use App\Models\DailyPickSet;
use App\Models\Guide;
use App\Models\ProductGroup;
use App\Services\Guides\CoveMarkup;
use App\Services\Seo\PageMeta;
use App\Services\Seo\StructuredData;
use App\Support\CurrentMarket;
use App\Support\PreviewAccess;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The Daily Cove: one article a day.
 *
 * A themed set of finds written up as prose, with each product under the
 * paragraph that names it, and a buying guide. Merged rather than kept apart
 * because each covers the other's hole — picks alone give no reason to return
 * once the novelty fades, and guides alone have no audience on the day they
 * publish. See docs/features/daily-cove.md.
 *
 * Every edition keeps a permanent URL. The archive is the SEO asset: ninety days
 * in, that is ninety indexed pages per market, each one a guide plus a set of
 * products plus the writing that connects them.
 */
class DailyCoveController extends Controller
{
    public function __invoke(
        Request $request,
        CurrentMarket $current,
        string $market,
        ?string $date = null,
    ): Response {
        // An admin, or somebody holding a signed preview link, reads a draft —
        // including one dated tomorrow, which is the whole point of checking it.
        $preview = PreviewAccess::allowed($request);

        $edition = $this->findEdition($current, $date, $preview);

        if ($edition === null) {
            throw new NotFoundHttpException;
        }

        $this->seo($edition, $current, $date !== null, $preview && ! $edition->isPublished());

        return Inertia::render('Daily/Edition', [
            // Renders a banner, and only ever true for somebody entitled to it.
            'preview' => $preview && ! $edition->isPublished(),
            'edition' => [
                'id' => $edition->id,
                'date' => $edition->drop_date->toDateString(),
                'label' => $edition->drop_date->format('j M Y'),
                'theme' => $edition->theme_title,
                'blurb' => $edition->theme_blurb,
                'isToday' => $edition->drop_date->isToday(),
                /*
                 * Long-form copy, with its link tokens resolved here rather
                 * than at write time.
                 *
                 * That is what lets the anchors follow the market the page is
                 * being read in, and lets a product that has since gone out of
                 * the catalogue degrade to plain text instead of leaving a dead
                 * link baked into a row nobody revisits.
                 */
                'editorial' => $this->editorial($edition, $current),
            ],

            'finds' => $this->finds($edition, $current),
            'guide' => $this->guide($edition, $current),
            'deals' => $this->deals($current),
            'archive' => $this->archive($current, $edition),
        ]);
    }

    /**
     * The biggest discounts we have seen most recently, for the sidebar.
     *
     * "Newest highest" is two orderings and they fight: the deepest discount in
     * the catalogue may be a month old, and the newest may be 4% off. Sorted by
     * discount within a recency window, so the column is both fresh and worth
     * looking at rather than a stale hall of fame.
     *
     * Measured against our own 30-day median, never a merchant's crossed-out
     * "was" price — the same rule the badges and the brand pages hold to, and
     * the reason a saving shown here can be defended.
     *
     * @return list<array<string, mixed>>
     */
    private function deals(CurrentMarket $current): array
    {
        $discount = '((median_price - min_price)::numeric / median_price) * 100';

        return ProductGroup::query()
            ->forMarket($current->get())
            ->presentable()
            ->whereNotNull('median_price')
            ->where('median_price', '>', 0)
            ->whereColumn('min_price', '<', 'median_price')
            // Seen in the last fortnight. A "deal" nobody has re-checked since
            // last month is a price we cannot stand behind.
            ->where('updated_at', '>=', now()->subDays(14))
            ->orderByRaw("{$discount} DESC")
            ->limit(6)
            ->get()
            ->map(fn (ProductGroup $group) => [
                'id' => $group->id,
                'title' => $group->title,
                'image' => $group->image_url,
                'price' => $group->min_price,
                'was' => $group->median_price,
                'discountPercent' => $group->discountPercent(),
                'url' => $current->url("p/{$group->id}/{$group->slug}"),
            ])
            ->values()
            ->all();
    }

    private function findEdition(CurrentMarket $current, ?string $date, bool $preview = false): ?DailyPickSet
    {
        $query = DailyPickSet::query()
            ->forMarket($current->get())
            // A preview is for the edition that has *not* dropped yet, so the
            // published filter is exactly what has to come off.
            ->unless($preview, fn ($q) => $q->published())
            ->with(['picks.group']);

        if ($date === null) {
            return $query->orderByDesc('drop_date')->first();
        }

        try {
            $parsed = CarbonImmutable::createFromFormat('Y-m-d', $date);
        } catch (\Throwable) {
            return null;
        }

        /*
         * A future date is a 404, not an empty page: tomorrow's edition is a
         * draft, and reaching it by URL would leak the theme and the finds.
         *
         * Unless this is a preview, where tomorrow's edition is precisely the
         * thing being checked — and the reader is an editor rather than a
         * player.
         */
        if ($parsed === false || ($parsed->isFuture() && ! $preview)) {
            return null;
        }

        return $query->where('drop_date', $parsed->toDateString())->first();
    }

    /**
     * The article, paragraph by paragraph, each carrying the products it names.
     *
     * The page used to be prose and then a grid: everything the writing was
     * about sat below everything the writing said, so a paragraph discussing a
     * kettle pointed at a card three screens down and the reader had to hold the
     * name in their head to find it. That is a catalogue with an introduction,
     * not an editorial.
     *
     * The pairing is already in the copy. A `[[product:12]]` token is the writer
     * saying "this paragraph is about that thing"; reading the ids back out per
     * paragraph is what lets the product appear where it is being discussed.
     *
     * @return list<array{html: string, groupIds: list<int>}>
     */
    private function editorial(DailyPickSet $edition, CurrentMarket $current): array
    {
        if (blank($edition->editorial)) {
            return [];
        }

        $groups = $edition->picks
            ->map(fn (DailyPick $pick) => $pick->group)
            ->filter()
            ->values();

        $allowed = [
            'brands' => $groups->pluck('brand')->filter()->unique()->values()->all(),
            'searches' => $groups->pluck('category')->filter()->unique()->values()->all(),
            'products' => $groups
                ->mapWithKeys(fn ($g) => [$g->id => ['slug' => $g->slug, 'title' => $g->title]])
                ->all(),
        ];

        $markup = app(CoveMarkup::class);
        $paragraphs = preg_split('/\R{2,}/u', trim((string) $edition->editorial)) ?: [];

        $out = [];
        $used = [];

        foreach ($paragraphs as $paragraph) {
            if (trim($paragraph) === '') {
                continue;
            }

            preg_match_all('/\[\[product:(\d+)/u', $paragraph, $matches);

            /*
             * Only ids the article was allowed to mention, and only the first
             * time each appears. A token naming a product that is not in today's
             * edition renders as plain text (see `CoveMarkup::render()`), and
             * repeating the same card because the copy repeats the name would
             * read as a stutter.
             */
            $ids = [];

            foreach ($matches[1] as $id) {
                $id = (int) $id;

                if (isset($allowed['products'][$id]) && ! isset($used[$id])) {
                    $used[$id] = true;
                    $ids[] = $id;
                }
            }

            $out[] = [
                'html' => $markup->render($paragraph, $current->get(), $allowed)['html'],
                'groupIds' => $ids,
            ];
        }

        return $out;
    }

    /** @return list<array<string, mixed>> */
    private function finds(DailyPickSet $edition, CurrentMarket $current): array
    {
        return $edition->picks
            ->filter(fn (DailyPick $pick) => $pick->group !== null)
            ->map(fn (DailyPick $pick) => [
                'id' => $pick->id,
                'groupId' => $pick->group->id,
                'title' => $pick->group->title,
                'image' => $pick->group->image_url,
                'price' => $pick->group->min_price,
                'merchantCount' => $pick->group->merchant_count,
                'discountPercent' => $pick->discount_percent,
                'blurb' => $pick->blurb,
                'url' => $current->url("p/{$pick->group->id}/{$pick->group->slug}"),
                'mindblown' => $pick->mindblown_count,
                'meh' => $pick->meh_count,
            ])
            ->values()
            ->all();
    }

    /** @return array<string, mixed>|null */
    private function guide(DailyPickSet $edition, CurrentMarket $current): ?array
    {
        $guide = $edition->guide;

        if ($guide === null) {
            return null;
        }

        return [
            'title' => $guide->title,
            'intro' => $guide->intro,
            'url' => $current->url("guides/{$guide->slug}"),
            'itemCount' => $guide->items()->count(),
            // The demand that justified writing it. Shown because it is the
            // honest answer to "why this guide" and because it is a fact only
            // this site has.
            'searchVolume' => $guide->source_volume,
        ];
    }

    /**
     * Recent editions, for the archive strip.
     *
     * @return list<array<string, mixed>>
     */
    private function archive(CurrentMarket $current, DailyPickSet $edition): array
    {
        return DailyPickSet::query()
            ->forMarket($current->get())
            ->published()
            ->where('id', '!=', $edition->id)
            ->orderByDesc('drop_date')
            ->limit(7)
            ->get(['drop_date', 'theme_title'])
            ->map(fn (DailyPickSet $set) => [
                'date' => $set->drop_date->toDateString(),
                'label' => $set->drop_date->format('j M'),
                'theme' => $set->theme_title,
                'url' => $current->url('daily/'.$set->drop_date->toDateString()),
            ])
            ->all();
    }

    private function seo(DailyPickSet $edition, CurrentMarket $current, bool $isArchive, bool $preview = false): void
    {
        $url = $isArchive
            ? url($current->url('daily/'.$edition->drop_date->toDateString()))
            : url($current->url('daily'));

        $meta = app(PageMeta::class);

        $meta->set(
            title: $edition->theme_title,
            description: $edition->theme_blurb ?? __('site.daily.seo_description'),
            /*
             * Always the dated card, even on /daily.
             *
             * The card a platform cached yesterday is the one it shows for the
             * link somebody posts today, and /daily is a different edition every
             * morning. Pointing at the dated image means a shared post keeps
             * showing the edition it was actually about.
             */
            image: url($current->url('og/daily/'.$edition->drop_date->toDateString().'.png')),
            canonical: $url,
        );

        /*
         * Today's edition canonicalises to /daily, and its dated twin
         * canonicalises to itself. Without this the same content sits at two
         * URLs for a day and the archive copy competes with the live page for
         * the same query.
         */
        $meta->addJsonLd(StructuredData::breadcrumbs([
            ['name' => 'Brandcoves', 'url' => url($current->url())],
            ['name' => __('site.daily.title'), 'url' => url($current->url('daily'))],
            ['name' => $edition->theme_title, 'url' => $url],
        ]));
    }
}
