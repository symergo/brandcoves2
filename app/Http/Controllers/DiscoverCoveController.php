<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\CommunityQuestion;
use App\Models\DailyPick;
use App\Models\DailyPickSet;
use App\Models\ProductGroup;
use App\Services\Guides\CoveMarkup;
use App\Services\Seo\PageMeta;
use App\Support\CurrentMarket;
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
 */
class DiscoverCoveController extends Controller
{
    /**
     * More than the front page's taste, fewer than the archive index's sixty.
     *
     * This page has to be a hub rather than a second copy of `/guides`: enough
     * titles that the range is obvious, then a link to the whole thing.
     */
    private const COVES = 12;

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

    public function __invoke(CurrentMarket $current): Response
    {
        app(PageMeta::class)->set(
            title: __('site.discover_cove.seo_title'),
            description: __('site.discover_cove.seo_description'),
            canonical: url($current->url('discover-cove')),
        );

        return Inertia::render('DiscoverCove', [
            'urls' => [
                'daily' => $current->url('daily'),
                'surprise' => $current->url('surprise'),
                'guides' => $current->url('guides'),
                'giftIdeas' => $current->url('gift-ideas'),
                // The one surface here whose content comes from other visitors
                // rather than from us. See docs/features/ask-others.md.
                'ask' => $current->url('ask'),
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
            'questions' => CommunityQuestion::query()
                ->forMarket($current->get())
                ->published()
                ->orderByDesc('published_at')
                ->limit(self::QUESTIONS)
                ->get()
                ->map(fn (CommunityQuestion $question) => [
                    'title' => $question->title,
                    'answers' => $question->answers_count,
                    'url' => $current->url("ask/{$question->id}/{$question->slug()}"),
                ])
                ->all(),

            'askUrl' => $current->url('ask'),

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
            'today' => $this->today($current),

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
            'surprises' => $this->surprises($current),

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
            'personas' => DailyPickSet::query()
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
                    'url' => $current->url($persona->kind->path((string) $persona->slug)),
                    // The drawing, not a product photo. This band said "no
                    // images" when the only image available was a photograph of
                    // a thing, which made a shelf of people look like a
                    // category of products; a scene is about the person and is
                    // the whole reason a reader recognises one.
                    'scene' => $persona->scene?->value,
                ])
                ->all(),

            'coves' => DailyPickSet::query()
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
                    'url' => $current->url($guide->kind->path((string) $guide->slug)),
                    // Why the Cove exists, and a fact no competitor has.
                    'searches' => $guide->source_volume,
                ])
                ->all(),
        ]);
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
            'url' => $current->url('daily'),
            'finds' => $edition->picks
                ->filter(fn (DailyPick $pick) => $pick->group !== null && $pick->group->in_stock)
                ->take(self::FINDS)
                ->map(fn (DailyPick $pick) => [
                    'id' => $pick->group->id,
                    'title' => $pick->group->title,
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
        $pool = ProductGroup::query()
            ->forMarket($current->get())
            ->presentable()
            ->where('surprise_score', '>', 0)
            ->orderByDesc('surprise_score')
            ->limit(self::SURPRISE_POOL)
            ->pluck('id');

        if ($pool->isEmpty()) {
            return [];
        }

        return ProductGroup::query()
            ->whereIn('id', $pool->shuffle()->take(self::SURPRISES))
            ->get()
            ->map(fn (ProductGroup $group) => [
                'id' => $group->id,
                'title' => $group->title,
                'brand' => $group->brand,
                'image' => $group->image_url,
                'price' => $group->min_price,
                'url' => $current->url("p/{$group->id}/{$group->slug}"),
            ])
            ->values()
            ->all();
    }
}
