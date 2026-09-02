<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\DailyPickSet;
use App\Models\ProductGroup;
use App\Services\Cove\CoveRail;
use App\Services\Cove\EditionPresenter;
use App\Services\Seo\PageMeta;
use App\Services\Seo\SocialCard;
use App\Services\Seo\StructuredData;
use App\Support\CurrentMarket;
use App\Support\PreviewAccess;
use Illuminate\Http\RedirectResponse;
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
    // The presentation is shared with the gift-ideas pages: a persona is the
    // same object served at a permanent URL, and the two must look identical.
    // The rail is shared with every Cove page for the same reason.
    public function __construct(
        private readonly EditionPresenter $presenter,
        private readonly CoveRail $rail,
    ) {}

    public function __invoke(
        Request $request,
        CurrentMarket $current,
        string $market,
        ?string $cove = null,
        ?string $slug = null,
    ): Response {
        $this->assertSegmentBelongsHere($current, $cove);

        // An admin, or somebody holding a signed preview link, reads a draft —
        // including one dated tomorrow, which is the whole point of checking it.
        $preview = PreviewAccess::allowed($request);

        $edition = $this->findEdition($current, $slug, $preview);

        if ($edition === null) {
            throw new NotFoundHttpException;
        }

        $this->seo($edition, $current, $slug !== null, $preview && ! $edition->isPublished());

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
                'editorial' => $this->presenter->editorial($edition, $current),
            ],

            'finds' => $this->presenter->finds($edition, $current),
            'guide' => $this->presenter->guide($edition, $current),
            'deals' => $this->deals($current),

            /*
             * Recent editions, and more products from today's categories.
             *
             * This replaced the archive strip that used to run across the
             * bottom of the page. The rail's Cove band *is* that strip — the
             * same query, the same handful of editions, the same links — so
             * rendering both would put one list on the page twice, once beside
             * the reading and once eight hundred pixels below it.
             */
            'rail' => $this->rail->for($edition, $current),
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
        $config = (array) config('giftcoves.deals');
        $discount = '((median_price - min_price)::numeric / median_price) * 100';

        $candidates = ProductGroup::query()
            ->forMarket($current->get())
            ->presentable()
            /*
             * Four filters, each removing a specific kind of junk.
             *
             * On percentage alone this column filled with silicone phone cases:
             * accurate, useless, and enough to make the page look like a bargain
             * bin. The percentage was never the problem — what it was applied to
             * was.
             */
            // A median drawn from one shop is that shop's opinion, and a
            // "discount" against it is that shop's marketing.
            ->comparable()
            // It sits beside gift writing on a gift site — so no consumables,
            // no fitment, no bulk. But a deal is a deal at any price, and a
            // heavily discounted expensive thing is the best row this column
            // can carry, so this is `worthShowing`, not `giftable`.
            ->worthShowing()
            ->whereNotNull('median_price')
            ->where('median_price', '>', 0)
            ->whereColumn('min_price', '<', 'median_price')
            // Below the floor a percentage says more about the price point than
            // about the offer.
            ->where('min_price', '>=', (int) $config['min_price'])
            // And the saving has to be real money, not only a big number.
            ->whereRaw('(median_price - min_price) >= ?', [(int) $config['min_saving']])
            // Seen in the last fortnight. A "deal" nobody has re-checked since
            // last month is a price we cannot stand behind.
            ->where('updated_at', '>=', now()->subDays((int) $config['window_days']))
            ->orderByRaw("{$discount} DESC")
            // Deliberately over-fetched: the per-brand cap below thins this out,
            // and asking for exactly six would leave the column short.
            ->limit((int) $config['limit'] * 10)
            ->get();

        /*
         * One product per brand.
         *
         * Six covers from one maker is one fact repeated six times — the same
         * reasoning as taking one feed per advertiser rather than the six
         * largest feeds. Breadth is what makes a short list worth reading.
         */
        $seen = [];

        return $candidates
            ->filter(function (ProductGroup $group) use (&$seen, $config): bool {
                $brand = mb_strtolower(trim((string) $group->brand));

                // No brand at all is not a brand they share; those compete on
                // their own merits rather than being collapsed together.
                if ($brand === '') {
                    return true;
                }

                $seen[$brand] = ($seen[$brand] ?? 0) + 1;

                return $seen[$brand] <= (int) $config['per_brand'];
            })
            ->take((int) $config['limit'])
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

    private function findEdition(CurrentMarket $current, ?string $slug, bool $preview = false): ?DailyPickSet
    {
        $query = DailyPickSet::query()
            ->forMarket($current->get())
            // Dailies only. `/daily` with no date orders by drop_date DESC,
            // which in Postgres puts the NULL-dated personas first.
            ->daily()
            // A preview is for the edition that has *not* dropped yet, so the
            // published filter is exactly what has to come off.
            ->unless($preview, fn ($q) => $q->published())
            ->with(['picks.group']);

        if ($slug === null) {
            return $query->orderByDesc('drop_date')->first();
        }

        $edition = $query->where('slug', $slug)->first();

        /*
         * A future edition is a 404, not an empty page: tomorrow's is a draft,
         * and reaching it by URL would leak the theme and the finds.
         *
         * Unless this is a preview, where tomorrow's edition is precisely the
         * thing being checked — and the reader is an editor rather than a
         * reader.
         */
        if ($edition !== null && $edition->drop_date->isFuture() && ! $preview) {
            return null;
        }

        return $edition;
    }

    /**
     * The old dated URL, permanently redirected to the named one.
     *
     * /daily/2026-08-29 is indexed and sits in three months of digest emails,
     * so it has to keep resolving. A 301 rather than a rewrite: there is one
     * canonical address for this page now, and telling a crawler that plainly is
     * the whole reason for renaming it.
     *
     * A date nothing was published on is a 404, exactly as it was before.
     */
    /**
     * The dated form under the localised segment.
     *
     * Separate from {@see self::legacyDated()} rather than one method with an
     * optional `$cove`, because **Laravel binds controller arguments by
     * position, not by name**. The two routes carry different parameter lists —
     * `{market}/{cove}/{date}` against `{market}/{date}` — so a shared
     * signature hands `$date` the segment on one of them. That failure is a
     * 404 on a URL whose route is registered and whose regex matches, which is
     * a genuinely confusing thing to debug.
     */
    public function dated(CurrentMarket $current, string $market, string $cove, string $date): RedirectResponse
    {
        $this->assertSegmentBelongsHere($current, $cove);

        return $this->redirectToEdition($current, $date);
    }

    /**
     * The dated form at the old `/daily/{date}`.
     *
     * Redirects straight to the final slug URL rather than to the new dated
     * form, so an address that is indexed and sits in three months of digest
     * emails reaches its destination in one hop. A chain costs link equity.
     */
    public function legacyDated(CurrentMarket $current, string $market, string $date): RedirectResponse
    {
        return $this->redirectToEdition($current, $date);
    }

    private function redirectToEdition(CurrentMarket $current, string $date): RedirectResponse
    {
        $edition = DailyPickSet::query()
            ->forMarket($current->get())
            ->daily()
            ->published()
            ->whereDate('drop_date', $date)
            ->first();

        if ($edition === null) {
            throw new NotFoundHttpException;
        }

        return redirect($current->get()->covePath($edition->slug), 301);
    }

    /**
     * Everything that used to live under `/daily`, permanently moved.
     *
     * Kept forever rather than for a decent interval. The archive is the SEO
     * asset the whole column exists to build, and these are the addresses it
     * was built at — indexed, linked from three months of digests, and pasted
     * into chats we cannot see.
     */
    public function moved(CurrentMarket $current, string $market, ?string $slug = null): RedirectResponse
    {
        return redirect($current->get()->covePath($slug ?? ''), 301);
    }

    /**
     * Refuse another market's word for this page.
     *
     * The routes admit every market's segment because they are declared once
     * under a `{market}` prefix, so `/es/cadeau-van-de-dag/...` matches the
     * pattern. Serving it would put the Spanish edition on a Dutch address:
     * duplicate content, carrying hreflang that contradicts it.
     *
     * Null is the legacy `/daily` path, which has no segment to check.
     */
    private function assertSegmentBelongsHere(CurrentMarket $current, ?string $cove): void
    {
        if ($cove !== null && $cove !== $current->get()->coveSegment()) {
            throw new NotFoundHttpException;
        }
    }

    private function seo(DailyPickSet $edition, CurrentMarket $current, bool $isArchive, bool $preview = false): void
    {
        $url = $isArchive
            ? url($current->get()->covePath($edition->slug))
            : url($current->get()->covePath());

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
            image: SocialCard::versioned(url($current->url('og/daily/'.$edition->drop_date->toDateString().'.png'))),
            canonical: $url,
        );

        /*
         * Today's edition canonicalises to /daily, and its dated twin
         * canonicalises to itself. Without this the same content sits at two
         * URLs for a day and the archive copy competes with the live page for
         * the same query.
         */
        $meta->addJsonLd(StructuredData::breadcrumbs([
            ['name' => 'GiftCoves', 'url' => url($current->url())],
            ['name' => __('site.daily.title'), 'url' => url($current->get()->covePath())],
            ['name' => $edition->theme_title, 'url' => $url],
        ]));
    }
}
