<?php

declare(strict_types=1);

namespace App\Services\Cove;

use App\Enums\CoveKind;
use App\Enums\PublishStatus;
use App\Models\CovePlan;
use App\Models\DailyPick;
use App\Models\DailyPickSet;
use App\Models\ProductGroup;
use App\Services\Guides\CoveMarkup;
use App\Support\CurrentMarket;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Where a Cove sends you next.
 *
 * Every Cove page ended in a dead end of its own. A Daily offered a strip of
 * past editions at the very bottom, a persona offered one link back to the
 * shelf it came off, an article offered nothing at all — so a reader who
 * arrived on a buying guide from search finished it with nowhere to go.
 *
 * Two answers, and the page puts them in two different places because they are
 * two different sizes of invitation:
 *
 * - **More Coves of this kind**, as cards under the article. Reading to the end
 *   is the strongest signal a reader gives, and what it asks for is another one
 *   of these — a card with a title and a line of what it is about, at the width
 *   the reading was, rather than a title in a gutter.
 *
 *   Its own kind only. Somebody who has just finished a persona has told you
 *   what shape of page they want, and answering that with three of each other
 *   shape is a table of contents rather than a recommendation. `/coves` is
 *   where the whole shelf is on show, and it is one click away in the header.
 * - **More products from the categories the Cove is about**, in the rail beside
 *   it. The writing named six things; the catalogue holds a few thousand more
 *   of the same sort, and until now the only way out of an article towards them
 *   was to go back to the search box and retype what you had been reading
 *   about. In the rail because it is something to glance at *during* the
 *   reading, not a conclusion to it.
 *
 * The kinds it can list are the bands `/coves` publishes minus the two that are
 * directories rather than writing — a Cove is something somebody wrote, and a
 * page of writing that offered a grid of brand names would be navigation
 * dressed as a recommendation.
 */
class CoveRail
{
    public function __construct(private readonly CoveMarkup $markup) {}

    /**
     * How many Coves.
     *
     * Six, because the card grid is three across and two rows is where it ends.
     * A third row past the bottom of an article somebody has already finished
     * is an index, and there is one of those a click away. Roughly what the
     * Daily's archive strip carried, which is the list this replaced.
     */
    private const COVES = 6;

    /**
     * And how many products, from how many categories.
     *
     * Two by three rather than one by six. A Cove's picks usually span two or
     * three categories, and six rows drawn from whichever one happened to sort
     * first would answer "more like this" with more of a third of it — on a
     * Cove about a home office, six desk lamps and no notebooks. Naming the two
     * categories and standing three under each says what the block is doing,
     * and keeps it to the same six rows the deals column beside it uses.
     */
    private const CATEGORIES = 2;

    private const PER_CATEGORY = 3;

    /**
     * The rail for one Cove.
     *
     * `$cove` is excluded from both lists — the page you are on is not
     * somewhere to go next, and neither are the products already printed on it.
     *
     * @return array{coves: array<string, mixed>|null, products: list<array<string, mixed>>, series: list<array<string, mixed>>|null}
     */
    public function for(DailyPickSet $cove, CurrentMarket $current): array
    {
        return [
            'coves' => $this->coves($cove, $current),
            'products' => $this->products($cove, $current),
            'series' => $this->series($cove, $current),
        ];
    }

    /**
     * The other parts of this Cove's series, if it is part of one.
     *
     * A season is published as several pages — "Kamperen, deel 2" — and a
     * numbered title with no way to reach the other numbers is a promise the
     * page does not keep. The reader is told where they are and can move
     * between the parts; without this the only route from part two to part
     * three is the general list of articles, where they sit among everything
     * else and in publication order.
     *
     * Rendered at the top of the article rather than in the rail beside it, and
     * that is a decision about what the block is for: the rest of the rail is
     * somewhere to go *afterwards*, and "which part am I reading" is something
     * you need before you start. It travels in the same prop because it comes
     * from the same service and the same request.
     *
     * ## Why the series lives on the plan
     *
     * `series_key` and `part` are columns on `cove_plans`, not on the edition —
     * the series is a fact about how the work was *planned*, and the edition is
     * an output every rebuild overwrites. So this reads the plan behind the
     * page and finds its siblings through theirs.
     *
     * Null unless at least two parts are actually published. One part is a page,
     * not a series, and a heading over a list of one reads as a failed load.
     *
     * @return list<array<string, mixed>>|null
     */
    private function series(DailyPickSet $cove, CurrentMarket $current): ?array
    {
        $plan = CovePlan::query()->where('edition_id', $cove->id)->first();

        if ($plan === null || $plan->series_key === null) {
            return null;
        }

        /*
         * Editions joined to their plans, rather than plans with their editions
         * eager-loaded: the ordering is `cove_plans.part` and the filter is on
         * the edition, so one is always the other's foreign side whichever way
         * round it is written — and this way `part` orders in SQL rather than in
         * PHP over a set that had to be fetched whole first.
         *
         * Every column is qualified and `published()` is spelled out rather than
         * called. Both tables carry `status`, `market` and `slug`, so the scope's
         * unqualified `where('status', ...)` is ambiguous across this join — an
         * error at the database, not a wrong answer, but only once somebody
         * publishes a series.
         */
        $siblings = DailyPickSet::query()
            ->join('cove_plans', 'cove_plans.edition_id', '=', 'daily_pick_sets.id')
            ->where('cove_plans.market', $plan->market->value)
            ->where('cove_plans.series_key', $plan->series_key)
            ->where('daily_pick_sets.status', PublishStatus::Published->value)
            ->whereNotNull('daily_pick_sets.published_at')
            ->where('daily_pick_sets.published_at', '<=', now())
            ->whereNotNull('daily_pick_sets.slug')
            ->orderBy('cove_plans.part')
            ->get([
                'daily_pick_sets.id',
                'daily_pick_sets.kind',
                'daily_pick_sets.slug',
                'daily_pick_sets.theme_title',
            ]);

        if ($siblings->count() < 2) {
            return null;
        }

        return $siblings->map(fn (DailyPickSet $part): array => [
            /*
             * The edition's title, not the plan's. They are usually the same
             * string, and where they differ the edition is what the reader is
             * looking at — an editor who retitled the plan after it was built
             * has not renamed the published page.
             */
            'title' => $part->theme_title,
            'url' => $current->url($part->kind->path((string) $part->slug, $current->get())),
            'current' => $part->id === $cove->id,
        ])->values()->all();
    }

    /**
     * The other Coves of this one's kind, and where the rest of them live.
     *
     * `published()`, so previewing an unapproved Cove never leaks a sibling
     * draft into the column beside it.
     *
     * Null when this is the market's first Cove of its kind. The component
     * drops the block rather than printing a heading over an empty list, which
     * reads as a failed load.
     *
     * @return array<string, mixed>|null
     */
    private function coves(DailyPickSet $cove, CurrentMarket $current): ?array
    {
        $key = self::sectionOf($cove->kind);

        $query = DailyPickSet::query()
            ->forMarket($current->get())
            ->published()
            ->where('id', '!=', $cove->id);

        match ($key) {
            'daily' => $query->daily(),
            'gift' => $query->personas(),
            'shop' => $query->shops(),
            default => $query->articles(),
        };

        /** @var Collection<int, DailyPickSet> $siblings */
        $siblings = $this->order($query, $key)
            ->limit(self::COVES)
            ->get(['id', 'kind', 'slug', 'drop_date', 'theme_title', 'theme_blurb']);

        if ($siblings->isEmpty()) {
            return null;
        }

        return [
            'key' => $key,
            /*
             * Where the whole of this kind lives — the same destinations
             * `/coves` sends its bands to, including the one that looks wrong:
             * `daily` points at `/daily`, which is today's edition rather than
             * an index, because that is the page every past edition hangs off.
             */
            'url' => $current->url(match ($key) {
                'daily' => $current->get()->coveSegment(),
                'gift' => 'gift-ideas',
                'shop' => 'shops',
                default => 'guides',
            }),
            'coves' => $siblings->map(fn (DailyPickSet $set): array => [
                'title' => $set->theme_title,
                /*
                 * Tokens flattened to their labels, exactly as `/coves` and
                 * `/guides` do it: a link inside a card whose whole surface is
                 * already a link is a target fighting its parent.
                 */
                'intro' => $this->markup->plain($set->theme_blurb),
                /*
                 * By slug, editions included. `/daily/{date}` still resolves
                 * but 301s onto `/daily/{slug}`, so linking by date would send
                 * every click in this column through a redirect.
                 */
                'url' => $current->url($set->kind->path((string) $set->slug, $current->get())),
                /*
                 * Only an edition carries one. A persona has no date on purpose
                 * — it never stops being current — so dating it here would
                 * invite the reader to treat an old one as stale.
                 */
                'date' => $set->drop_date?->format('j M Y'),
            ])->values()->all(),
        ];
    }

    /**
     * More of what the Cove is about: products from its own categories.
     *
     * The categories come from the picks rather than from the prose, and the
     * two the Cove has most of win — a Cove is *about* whatever its shortlist
     * is mostly made of, and a category represented by one product out of seven
     * is a stray classification from a feed rather than a subject.
     *
     * Empty when nothing on the page carries a category. That is the normal
     * state of an advice article, which has no products at all, and the
     * component drops the block: an empty "more like this" is worse than none.
     *
     * @return list<array<string, mixed>>
     */
    private function products(DailyPickSet $cove, CurrentMarket $current): array
    {
        /** @var Collection<int, ProductGroup> $groups */
        $groups = $cove->picks
            ->map(fn (DailyPick $pick) => $pick->group)
            ->filter()
            ->values();

        $exclude = $groups->pluck('id')->all();

        $categories = $groups
            ->pluck('category')
            ->filter()
            ->countBy()
            ->sortDesc()
            ->keys()
            ->take(self::CATEGORIES)
            ->all();

        $bands = [];

        foreach ($categories as $category) {
            $products = ProductGroup::query()
                ->forMarket($current->get())
                ->presentable()
                /*
                 * `worthShowing()`, not `giftable()`. This sits beside editorial
                 * rather than under "gift ideas for", and the two columns answer
                 * different questions: a €700 espresso machine is a bad gift
                 * suggestion and a perfectly good thing to show somebody reading
                 * about coffee. See docs/features/giftability.md.
                 */
                ->worthShowing()
                ->where('category', $category)
                // Never something already printed on this page. "More like
                // this" that opens with what you just read is a bug the reader
                // can see.
                ->whereNotIn('id', $exclude)
                /*
                 * Most-compared first.
                 *
                 * The one thing this site knows that a shop does not is what a
                 * product costs everywhere, so a rail that leads with the rows
                 * carrying several offers is leading with the reason to click.
                 * `first_seen_at` breaks the ties, which keeps the column
                 * turning over as the catalogue grows instead of freezing on
                 * whatever was ingested first.
                 */
                ->orderByDesc('merchant_count')
                ->orderByDesc('first_seen_at')
                ->limit(self::PER_CATEGORY)
                ->get(['id', 'title', 'slug', 'image_url', 'min_price', 'merchant_count']);

            if ($products->isEmpty()) {
                continue;
            }

            $bands[] = [
                'category' => $category,
                /*
                 * A search rather than a category page, because there is no
                 * category page: `category` is a feed's own word for the shelf
                 * it put the product on, in the market's language, and the
                 * search box is what turns one into a browsable list. The same
                 * destination a `[[search:...]]` token in the prose resolves to.
                 */
                'url' => $current->url('search').'?'.http_build_query(['q' => $category]),
                'products' => $products->map(fn (ProductGroup $group): array => [
                    'id' => $group->id,
                    'title' => $group->title,
                    'image' => $group->image_url,
                    'price' => $group->min_price,
                    'merchantCount' => $group->merchant_count,
                    'url' => $current->url("p/{$group->id}/{$group->slug}"),
                ])->values()->all(),
            ];
        }

        return $bands;
    }

    /**
     * Which band a kind belongs to.
     *
     * Four bands out of six kinds: a buying guide, a seasonal guide and an
     * advice article are one section to a reader — they share a URL space, an
     * index and a name on the header — so an advice article's rail lists the
     * buying guides too, which is the point of writing one.
     *
     * The keys are `/coves`'s keys on purpose: the copy for a heading and for
     * "all of these" already exists as `site.coves.{key}_heading` and
     * `site.coves.{key}_all`, and a second set of names for the same sections
     * would be more strings to keep in step across four languages.
     */
    public static function sectionOf(CoveKind $kind): string
    {
        return match ($kind) {
            CoveKind::Daily => 'daily',
            CoveKind::Persona => 'gift',
            CoveKind::Shop => 'shop',
            CoveKind::Guide, CoveKind::Seasonal, CoveKind::Advice => 'smart',
        };
    }

    /**
     * Newest first, by the column each kind actually has.
     *
     * `drop_date` is null on five of the six kinds and Postgres sorts
     * `ORDER BY ... DESC` NULLS FIRST, so ordering every kind by it would put
     * the dateless Coves at the top in whatever order the planner happened to
     * write them. The same trap `scopeDaily()` documents.
     *
     * @param  Builder<DailyPickSet>  $query
     * @return Builder<DailyPickSet>
     */
    private function order(Builder $query, string $key): Builder
    {
        return $key === 'daily'
            ? $query->orderByDesc('drop_date')
            : $query->orderByDesc('published_at');
    }
}
