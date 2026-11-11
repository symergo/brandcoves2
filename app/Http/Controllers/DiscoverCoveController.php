<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\CommunityQuestion;
use App\Models\DailyPick;
use App\Models\DailyPickSet;
use App\Models\GiftLanding;
use App\Models\ProductGroup;
use App\Services\Cove\CoveCaches;
use App\Services\Gift\GiftLandingCopy;
use App\Services\Guides\CoveMarkup;
use App\Services\Seo\PageMeta;
use App\Support\CurrentMarket;
use Illuminate\Support\Facades\Cache;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The Discover Cove: one page explaining the three ways this site shows you
 * something you were not looking for.
 *
 * The same argument as the Gift Cove. Daily, Surprise and the Coves archive
 * were each reachable from the header and collectively unexplained — three
 * entries that read as three unrelated links rather than as one half of the
 * product. "Surprise me" in particular promises nothing a visitor can evaluate
 * before pressing it.
 *
 * `/discover-cove`, not `/discover`: `/discover/{mode?}` is the mode dial from
 * discovery-modes.md and is a different thing — a surface you operate, not a
 * page that explains. Following the `/gift-cove` precedent, which exists for
 * exactly this reason.
 *
 * No *numbers*. A hub that counts things is the catalogue-counter mistake from
 * homepage.md in a new place, and every total worth showing belongs to a Cove
 * and is already on that Cove's own page.
 *
 * The Coves themselves are a different matter and are listed here. Two of the
 * three cards describe something the visitor cannot see from this page — today's
 * edition and a Surprise both have to be opened — but the archive is the one
 * whose value *is* its contents. A card saying "long reads around a theme"
 * sends the reader one click away to find out whether any of them is about
 * anything they care about; a dozen titles answers that here.
 *
 * Rebuilt 2026-09-26 with the owner (see DiscoverCove.tsx for the layout and
 * why): the search card and Find a gift left the top, the explainer tiles
 * went, This or that came in, every band shows six at most, and the question
 * board and earlier editions show only with three or more.
 */
class DiscoverCoveController extends Controller
{
    /** Earlier editions listed under today's: a week of them. */
    private const EARLIER_EDITIONS = 7;

    /**
     * Below this many, a list band is left out rather than shown thin. One
     * earlier edition or one question under its own heading read as an empty
     * shelf in the 2026-09-26 review; three is the fewest that reads as a list.
     */
    private const MIN_LIST = 3;

    /** Gift landing pages per person, as a row of words under the personas. */
    private const FOR_WHOM = 8;

    /**
     * More than the front page's taste, fewer than the archive index's sixty.
     *
     * This page has to be a hub rather than a second copy of `/guides`: enough
     * titles that the range is obvious, then a link to the whole thing. Six
     * since 2026-09-26: twelve made this one band longer than the rest of
     * the page on a phone.
     */
    private const COVES = 6;

    /**
     * Questions on the hub.
     *
     * Six, not twenty. This is a landing page for four surfaces and the board
     * is one of them — a longer list would make Ask look like the whole page,
     * and `/ask` is one click away for anybody who wants the rest.
     */
    private const QUESTIONS = 6;

    /** Finds from today's edition. An invitation to it, not a copy of it. */
    private const FINDS = 4;

    /**
     * Personas on the hub.
     *
     * Six, against the Coves' twelve. The shelf is deliberately short — these
     * are written one at a time — so a dozen slots would show mostly gaps in
     * every market for months, and a band that looks unfinished argues against
     * the surface it is there to introduce.
     */
    private const PERSONAS = 6;

    /**
     * Surprises on the hub.
     *
     * Four, sampled from the same top slice `/surprise` draws from — so the
     * band is different on every visit, which is the one property this surface
     * has to demonstrate rather than describe.
     */
    private const SURPRISES = 4;

    /** How deep the sample reaches. Matches `SerendipityController::POOL`. */
    private const SURPRISE_POOL = 200;

    /** Ten minutes for what the page caches; see each use for why. */
    private const TTL = 600;

    public function __invoke(CurrentMarket $current): Response
    {
        app(PageMeta::class)->set(
            title: __('site.discover_cove.seo_title'),
            description: __('site.discover_cove.seo_description'),
            canonical: url($current->url('discover-cove')),
        );

        // Six drawn at once: four for the Surprise band, and two with a picture
        // for the This or that band, so the two never show the same product.
        $drawn = collect($this->surprises($current));
        $pair = $drawn->filter(fn (array $find) => $find['image'] !== null)->take(-2)->values();

        // Half a pair is no pair, and must not cost the Surprise band a product.
        if ($pair->count() < 2) {
            $pair = collect();
        }
        $surprises = $drawn->reject(fn (array $find) => $pair->contains('id', $find['id']))->take(self::SURPRISES)->values();

        /*
         * The Cove bands (today's edition, the days before it, the personas
         * and the guides), cached per market for ten minutes. Four queries and
         * a loaded edition per view, for lists that change when a Cove is
         * published, which forgets them (CoveCaches::forgetMarket); the TTL
         * also ends at the Daily's release. Today's finds are filtered on
         * stock inside the cache, so a find that sold out can stay up to ten
         * minutes: a hub card, not a buy button. Plain arrays only.
         */
        $bands = Cache::remember(
            CoveCaches::discoverKey($current->get()),
            CoveCaches::ttl(self::TTL),
            fn (): array => [
                'today' => $this->today($current),
                'dailies' => $this->dailies($current),
                'personas' => $this->personas($current),
                'coves' => $this->coves($current),
            ],
        );

        $questions = CommunityQuestion::query()
            ->forMarket($current->get())
            ->published()
            ->orderByDesc('published_at')
            ->limit(self::QUESTIONS)
            ->get();

        return Inertia::render('DiscoverCove', [
            'urls' => [
                'daily' => $current->get()->covePath(),
                // "Alle edities" under the earlier editions (2026-10-02).
                'dailyArchive' => $current->get()->covePath('archive'),
                'surprise' => $current->url('surprise'),
                'guides' => $current->url('guides'),
                'giftIdeas' => $current->url('gift-ideas'),
                // The one surface here whose content comes from other visitors
                // rather than from us. See docs/features/ask-others.md.
                'ask' => $current->url('ask'),
                // This or that (docs/features/taste-discovery.md), and the
                // Find a gift for somebody who would rather answer questions.
                'taste' => $current->url('gift/taste'),
                'gift' => $current->url('gift'),
            ],

            /*
             * What the board is currently chewing on.
             *
             * The same argument as listing the Coves below: two of the four
             * cards describe something you cannot see from here, and this one's
             * value *is* its contents. "Let other people suggest something"
             * cannot be evaluated in advance; six real questions can, and an
             * unanswered one is the most effective invitation the feature has —
             * somebody who knows the answer will recognise it on sight.
             *
             * Still no counts. A hub that totals things is the catalogue-counter
             * mistake in a new place; the answer count belongs to the question
             * it is about and travels with it.
             */
            'questions' => $questions->count() < self::MIN_LIST ? [] : $questions
                ->map(fn (CommunityQuestion $question) => [
                    'title' => $question->title,
                    'answers' => $question->answers_count,
                    'url' => $current->url("ask/{$question->id}/{$question->slug()}"),
                ])
                ->all(),

            /*
             * Today's edition, shown rather than described.
             *
             * This page was four cards and a list of titles, which made it a
             * table of contents for the discovery half rather than a landing
             * page for it — and the Daily Cove is the one surface here whose
             * whole argument is "this changes, come back tomorrow". A dated
             * edition with real finds on it makes that argument; a card saying
             * "a new edition every day" asks the reader to take it on trust.
             *
             * Null before the first edition in a market, and the band simply
             * does not render — an empty shelf is worse than no shelf.
             */
            'today' => $bands['today'],

            /*
             * The editions before today's, as a list (owner's call,
             * 2026-09-13): the page opens with the search, then today's
             * edition, then the days before it, then the cards for every kind
             * of Cove. A visitor who liked today's wants to know there is a
             * yesterday; a row per edition says so without another band of
             * product tiles.
             */
            'dailies' => $bands['dailies'],

            /*
             * A handful of surprises, resampled on every visit.
             *
             * Surprise was the one card with nothing underneath it, which left
             * the page arguing for three surfaces and asserting a fourth. It is
             * also the surface whose promise is least evaluable in advance —
             * "show me something I didn't know existed" cannot be judged until
             * you have seen one — so it is the card that benefits most from
             * having its output on the page.
             *
             * Reads `surprise_score`, which `ScoreSerendipity` computed after
             * the last ingest. Nothing is scored per request.
             */
            'surprises' => $surprises->all(),
            'pair' => $pair->all(),

            /*
             * The gift landing pages for a whole person ("gift ideas for
             * dad"), most products first: a row of words under the personas,
             * the same list the /gift-ideas shelf shows. Empty until the
             * nightly planner has recorded a page in this market.
             */
            'forWhom' => GiftLanding::query()
                ->forMarket($current->get())
                ->whereNull('interest')
                ->orderByDesc('product_count')
                ->limit(self::FOR_WHOM)
                ->get(['recipient', 'path'])
                ->map(fn (GiftLanding $page) => [
                    'label' => (new GiftLandingCopy($current->get(), $page->recipient))->heading(),
                    'url' => $page->path,
                ])
                ->all(),

            /*
             * The persona shelf, by name.
             *
             * The same argument the Coves band below makes, and the one this
             * class's docblock already states: two of these surfaces describe
             * something you cannot see from here, and this is not one of them.
             * "Presents chosen around a person" is a category; "the coffee
             * obsessive" and "the one who already has everything" are the
             * reason to click, and a reader recognises the person they are
             * shopping for on sight or does not.
             *
             * Empty until a market has published one, and then the band *and*
             * its card both disappear — see the note on `sections` in
             * `DiscoverCove.tsx`. Sending a hub visitor to an empty shelf is
             * worse than not offering the surface yet.
             *
             * No counts, like everything else here. `/gift-ideas` shows a find
             * count per persona because that is the shelf itself; a hub that
             * totals things is the catalogue-counter mistake in a new place.
             */
            'personas' => $bands['personas'],

            'coves' => $bands['coves'],
        ]);
    }

    /**
     * The persona shelf, by name; see the prop in __invoke() for why.
     *
     * @return list<array<string, mixed>>
     */
    private function personas(CurrentMarket $current): array
    {
        return DailyPickSet::query()
            ->forMarket($current->get())
            ->personas()
            ->published()
            // Matches the shelf at /gift-ideas. `published_at` is stamped
            // once at first build and never refreshed by a rebuild, so this
            // is stable rather than reshuffling when products refresh.
            ->orderByDesc('published_at')
            ->limit(self::PERSONAS)
            ->get(['id', 'kind', 'slug', 'theme_title', 'theme_blurb', 'scene'])
            ->map(fn (DailyPickSet $persona): array => [
                'title' => $persona->theme_title,
                'intro' => app(CoveMarkup::class)->plain($persona->theme_blurb),
                'url' => $current->url($persona->kind->path((string) $persona->slug, $current->get())),
                // The drawing, not a product photo. This band said "no
                // images" when the only image available was a photograph of
                // a thing, which made a shelf of people look like a
                // category of products; a scene is about the person and is
                // the whole reason a reader recognises one.
                'scene' => $persona->scene?->value,
            ])
            ->all();
    }

    /**
     * The newest guides, as cards.
     *
     * @return list<array<string, mixed>>
     */
    private function coves(CurrentMarket $current): array
    {
        return DailyPickSet::query()
            ->forMarket($current->get())
            ->articles()
            ->published()
            ->orderByDesc('published_at')
            ->limit(self::COVES)
            ->get(['id', 'kind', 'slug', 'theme_title', 'theme_blurb', 'source_volume'])
            ->map(fn (DailyPickSet $guide): array => [
                'title' => $guide->theme_title,
                // A card blurb, not an article: tokens flattened to their
                // labels, exactly as the archive index does it. A link
                // inside a card whose whole surface is already a link is a
                // target fighting its parent.
                'intro' => app(CoveMarkup::class)->plain($guide->theme_blurb),
                'url' => $current->url($guide->kind->path((string) $guide->slug, $current->get())),
                // Why the Cove exists, and a fact no competitor has.
                'searches' => $guide->source_volume,
            ])
            ->all();
    }

    /**
     * The most recent published edition, with a few of its finds.
     *
     * Deliberately the same shape `HomeController::today()` builds, because it
     * is the same band: an edition is a theme, a date and a handful of
     * products, and two surfaces describing it differently is the drift this
     * codebase keeps writing about.
     *
     * In stock only. An unbuyable product is a worse first impression than one
     * fewer card, and this page is often somebody's second ever click.
     *
     * @return array<string, mixed>|null
     */
    /**
     * The editions before the one shown above, newest first.
     *
     * `skip(1)` is "not today's": `today()` takes the newest edition by
     * `drop_date`, so the next ones by the same order are exactly the earlier
     * ones. A week of them; the Daily Cove's own archive has the rest.
     *
     * @return list<array{date: string, title: string, url: string}>
     */
    private function dailies(CurrentMarket $current): array
    {
        $market = $current->get();

        $editions = DailyPickSet::query()
            ->forMarket($market)
            ->daily()
            ->published()
            ->orderByDesc('drop_date')
            ->skip(1)
            ->limit(self::EARLIER_EDITIONS)
            ->get(['id', 'kind', 'slug', 'drop_date', 'theme_title']);

        if ($editions->count() < self::MIN_LIST) {
            return [];
        }

        return $editions
            ->map(fn (DailyPickSet $edition): array => [
                'date' => $edition->drop_date->toDateString(),
                'title' => $edition->theme_title,
                'url' => $current->url($edition->kind->path((string) $edition->slug, $market)),
            ])
            ->all();
    }

    private function today(CurrentMarket $current): ?array
    {
        $edition = DailyPickSet::query()
            ->forMarket($current->get())
            // See HomeController: NULLS FIRST would put a persona here.
            ->daily()
            ->published()
            ->with(['picks.group'])
            ->orderByDesc('drop_date')
            ->first();

        if ($edition === null) {
            return null;
        }

        return [
            'theme' => $edition->theme_title,
            'blurb' => $edition->theme_blurb,
            'date' => $edition->drop_date->toDateString(),
            'label' => $edition->drop_date->format('j M'),
            'url' => $current->get()->covePath(),
            'finds' => $edition->picks
                ->filter(fn (DailyPick $pick) => $pick->group !== null && $pick->group->in_stock)
                ->take(self::FINDS)
                ->map(fn (DailyPick $pick) => [
                    'id' => $pick->group->id,
                    'title' => $pick->group->displayTitle(),
                    'image' => $pick->group->image_url,
                    'price' => $pick->group->min_price,
                    'url' => $current->url("p/{$pick->group->id}/{$pick->group->slug}"),
                ])
                ->values()
                ->all(),
        ];
    }

    /**
     * A few things worth not having gone looking for.
     *
     * Top slice by score, shuffled inside it — the same shape as
     * `SerendipityController::sample()`, and for the same reason: `ORDER BY
     * random()` over the whole table is both slow and wrong, because it returns
     * median products, and a surface whose purpose is surprise must not show
     * everyone the same four things forever.
     *
     * No blurbs. The Surprise page fetches a line of description per find
     * because six unfamiliar objects need saying what they are; four cards
     * under a heading on a hub are an invitation to that page rather than a
     * substitute for it, and the extra query is not worth it here.
     *
     * @return list<array<string, mixed>>
     */
    private function surprises(CurrentMarket $current): array
    {
        /*
         * The pool's ids cached per market for ten minutes, the draw from it
         * per request: the band still differs on every visit, which is the
         * point of it, without sorting the market's products by score each
         * time. Scores change when `ScoreSerendipity` runs, twice a day at
         * most. Ints only, which the cache can hold.
         */
        $pool = collect(Cache::remember(
            'bc:discover:'.$current->value().':surprise-pool',
            self::TTL,
            fn (): array => ProductGroup::query()
                ->forMarket($current->get())
                ->presentable()
                ->where('surprise_score', '>', 0)
                ->orderByDesc('surprise_score')
                ->limit(self::SURPRISE_POOL)
                ->pluck('id')
                ->map(fn ($id): int => (int) $id)
                ->all(),
        ));

        if ($pool->isEmpty()) {
            return [];
        }

        return ProductGroup::query()
            ->whereIn('id', $pool->shuffle()->take(self::SURPRISES + 2))
            ->get()
            ->map(fn (ProductGroup $group) => [
                'id' => $group->id,
                'title' => $group->displayTitle(),
                'brand' => $group->brand,
                'image' => $group->image_url,
                'price' => $group->min_price,
                'url' => $current->url("p/{$group->id}/{$group->slug}"),
            ])
            ->values()
            ->all();
    }
}
