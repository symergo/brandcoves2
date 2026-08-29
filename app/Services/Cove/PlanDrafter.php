<?php

declare(strict_types=1);

namespace App\Services\Cove;

use App\Enums\CoveKind;
use App\Enums\Interest;
use App\Enums\Market;
use App\Models\CovePlan;
use App\Models\GuideTopic;
use App\Models\User;
use App\Services\Curation\PlanCurator;
use App\Services\Gift\AngleMap;
use App\Services\Guides\TopicPlanner;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * "Give me ten more of these to curate."
 *
 * The planner's blank page problem, one level up. `CuratePlan` solved it for the
 * products on a plan — an editor opening an empty shortlist is being asked to
 * invent seven products from nothing — and left it untouched for the plans
 * themselves. A market with no guide plans opens on an empty table, and the only
 * way out was to think of a topic, type it, slug it, and do it again nine times.
 *
 * Every kind here already had a source of ideas. None of them was reachable from
 * the screen where you would want it: the observance calendar only appeared as a
 * console command, the mined topic queue lived on a different navigation entry
 * behind a per-row button, and the interest vocabulary the gift wizard is built
 * on had never been used for editorial at all.
 *
 * ## Drafts, always
 *
 * Every plan this writes is `draft`, exactly like the ones a person types. The
 * point of drafting is that somebody reads it before it publishes, and a button
 * that produced approved plans would be a content farm with a nicer interface.
 * Nothing here calls a model, so pressing it costs nothing but rows — invariant
 * 1 is not even in play.
 *
 * ## Not every kind has a source, and it says so
 *
 * An advice article is an opinion about how to shop; nothing in the database
 * suggests one, and inventing titles from a template would fill the queue with
 * plausible-looking work nobody meant. A Shop Cove is seeded from the repository
 * by `bc:seed-shop-coves` and has no builder that reads a plan. Both are refused
 * with the reason rather than quietly returning zero.
 *
 * Deliberately *not* merged with `bc:plan-coves`, which answers a different
 * question: that fills every themed day in a window, across all markets, as a
 * calendar. This takes N ideas for one market and one kind, as a queue top-up.
 */
final readonly class PlanDrafter
{
    /**
     * How far ahead the Daily source will look for unplanned days.
     *
     * A year. Beyond that the observance calendar is repeating itself and a plan
     * written now will be read by somebody who has forgotten it exists.
     */
    private const DAILY_HORIZON_DAYS = 365;

    /** How a drafted persona records the interest it came from. */
    private const INTEREST_MARK = 'Drafted from the gift wizard interests (';

    public function __construct(
        private ObservanceCalendar $calendar,
        private EditionBuilder $builder,
        private PlanCurator $curator,
        private TopicPlanner $topics,
        private AngleMap $angles,
        private PlanSlugs $slugs,
    ) {}

    /**
     * Draft up to `$count` plans of one kind, for one market.
     *
     * `$withProducts` pre-fills each plan with the shortlist the builder would
     * have chosen. Worth turning off for a large run: it is a ranked selection
     * query per plan, and thirty of them is a slow button.
     */
    public function draft(
        CoveKind $kind,
        Market $market,
        int $count,
        ?User $author = null,
        bool $withProducts = true,
    ): DraftedPlans {
        if ($count < 1) {
            throw new InvalidArgumentException('Ask for at least one plan.');
        }

        return match ($kind) {
            CoveKind::Daily => $this->fromCalendar($market, $count, $author, $withProducts),
            CoveKind::Guide, CoveKind::Seasonal => $this->fromTopics($kind, $market, $count, $author),
            CoveKind::Persona => $this->fromInterests($market, $count, $author, $withProducts),

            CoveKind::Advice => DraftedPlans::none(
                'Advice articles are not drafted automatically. Nothing in the data suggests one — an advice piece is '
                .'an opinion about how to shop, not a topic the catalogue or the search log can propose. Write the '
                .'title yourself, or send a list to the editorial API.'
            ),

            CoveKind::Shop => DraftedPlans::none(
                'Shop Coves are seeded from the repository with bc:seed-shop-coves, not planned. Nothing builds a Shop '
                .'plan, so a drafted one would sit here unbuildable.'
            ),
        };
    }

    /** Which kinds this can actually draft, for a screen that has to offer a choice. */
    public function canDraft(CoveKind $kind): bool
    {
        return ! in_array($kind, [CoveKind::Advice, CoveKind::Shop], true);
    }

    /**
     * Daily Coves, from the observance calendar.
     *
     * The next unplanned themed days, in order. A day that already has a plan is
     * skipped whatever its status — somebody has decided something about it, and
     * the unique index on dated rows means a second plan for one date cannot be
     * inserted anyway.
     */
    private function fromCalendar(Market $market, int $count, ?User $author, bool $withProducts): DraftedPlans
    {
        // From tomorrow: today's edition has already been built, and a plan for
        // it would be read too late to change anything.
        $date = CarbonImmutable::tomorrow();
        $end = $date->addDays(self::DAILY_HORIZON_DAYS);

        $taken = CovePlan::query()
            ->where('market', $market->value)
            ->whereNotNull('drop_date')
            ->whereDate('drop_date', '>=', $date->toDateString())
            ->pluck('drop_date')
            ->map(fn ($d) => CarbonImmutable::instance($d)->toDateString())
            ->flip();

        $plans = [];
        $suggested = 0;

        while (count($plans) < $count && $date->lessThan($end)) {
            $day = $date;
            $date = $date->addDay();

            if ($taken->has($day->toDateString())) {
                continue;
            }

            $observance = $this->calendar->themeFor($day, $market);

            if ($observance === null) {
                continue;
            }

            $plan = CovePlan::create([
                'market' => $market->value,
                'kind' => CoveKind::Daily->value,
                'drop_date' => $day->toDateString(),
                'title' => $observance->title($market),
                'blurb' => $observance->blurb($market),
                'queries' => $observance->queries,
                'status' => 'draft',
                'created_by' => $author?->id,
                'note' => $observance->evergreen
                    ? 'Rotation theme ('.$observance->key.') — no named day falls here. Replace it with something better if you have one.'
                    : 'Drafted from the observance calendar ('.$observance->key.'). Edit or approve.',
            ]);

            $plans[] = $plan;
            $suggested += $this->prefill($plan, $withProducts, $plans);
        }

        return new DraftedPlans(
            $plans,
            $suggested,
            count($plans) < $count
                ? 'The next '.self::DAILY_HORIZON_DAYS.' days in '.$market->value
                    .' are planned as far as the observance calendar reaches.'
                : null,
        );
    }

    /**
     * Buying and seasonal guides, from the mined topic queue.
     *
     * The queue is the only honest source of guide topics there is: the phrase
     * people actually typed into this site, with the number of times they typed
     * it. `TopicPlanner` does the join and marks the topic queued, so the same
     * idea is never handed out twice.
     *
     * Ranked differently per kind, because the two are urgent for different
     * reasons. A search topic is worth writing in proportion to its demand; a
     * seasonal one is worth writing before its window opens, and a high score in
     * March is no reason to write the Halloween guide first.
     */
    private function fromTopics(CoveKind $kind, Market $market, int $count, ?User $author): DraftedPlans
    {
        $seasonal = $kind === CoveKind::Seasonal;

        $topics = GuideTopic::query()
            ->where('market', $market->value)
            ->whereNull('plan_id')
            ->where('status', 'candidate')
            ->when($seasonal, fn ($q) => $q->where('origin', 'seasonal'))
            ->when(! $seasonal, fn ($q) => $q->where('origin', '!=', 'seasonal'))
            ->notRecentlyAttempted()
            ->when($seasonal, fn ($q) => $q->orderBy('season_from'))
            ->when(! $seasonal, fn ($q) => $q->orderByDesc('score'))
            ->limit($count)
            ->get();

        $plans = [];
        $suggested = 0;

        foreach ($topics as $topic) {
            $plan = $this->topics->draft($topic, $author);
            $plans[] = $plan;
            $suggested += $plan->items()->count();
        }

        return new DraftedPlans(
            $plans,
            $suggested,
            count($plans) < $count
                ? 'The topic queue for '.$market->value.' has no more unplanned '
                    .($seasonal ? 'seasonal windows' : 'search topics')
                    .'. Mine more with bc:refresh-discovery and bc:pull-charts, or add one by hand under Cove topics.'
                : null,
        );
    }

    /**
     * Gift personas, from the interests the gift wizard is built on.
     *
     * A persona is a page about a *person*, and the closest thing this codebase
     * has to a vocabulary of people is `App\Enums\Interest` — twenty interests,
     * translated into every market's language, each already mapped to concrete
     * product nouns by `AngleMap`. Those nouns are the reason this is worth
     * doing automatically rather than typing: "photography" as a query finds
     * gift listicles, and "statief, cameratas, polarisatiefilter" finds
     * products.
     *
     * The title is a placeholder and reads like one. "The cottagecore herbalist"
     * is what a persona should be called, and no enum will ever produce it; what
     * this can do is put a curated shortlist in front of somebody who will then
     * think of the name.
     */
    private function fromInterests(Market $market, int $count, ?User $author, bool $withProducts): DraftedPlans
    {
        $spokenFor = $this->interestsAlreadyPlanned($market);

        $plans = [];
        $suggested = 0;
        $language = $market->language();

        foreach (Interest::cases() as $interest) {
            if (count($plans) >= $count) {
                break;
            }

            if ($spokenFor->has($interest->value)) {
                continue;
            }

            $label = __('site.gift.interests.'.$interest->value, [], $language);

            $plan = CovePlan::create([
                'market' => $market->value,
                'kind' => CoveKind::Persona->value,
                'title' => __('site.gift_ideas.draft_title', ['interest' => $label], $language),
                'slug' => $this->slugs->free($market, $label),
                /*
                 * The wizard's own queries for this interest, seed and widened
                 * rows together. Product nouns in the catalogue's language — the
                 * one part of this a person would otherwise have to look up.
                 */
                'queries' => array_slice($this->angles->queriesFor($market, [$interest->value]), 0, 12),
                'status' => 'draft',
                'created_by' => $author?->id,
                /*
                 * The marker the next run reads to know this interest is taken.
                 * On the note rather than in a column because it is a fact about
                 * how the row was made, not about what the page is — and a
                 * column would have to be carried by every plan ever written by
                 * hand, which is all of the interesting ones.
                 */
                'note' => self::INTEREST_MARK.$interest->value.') — a placeholder title and the wizard\'s own search '
                    .'terms. Rename it after somebody a reader would recognise before you approve it.',
            ]);

            $plans[] = $plan;
            $suggested += $this->prefill($plan, $withProducts, $plans);
        }

        return new DraftedPlans(
            $plans,
            $suggested,
            count($plans) < $count
                ? 'Every interest the gift wizard knows about already has a persona in '.$market->value
                    .'. The next ones have to come from somewhere a machine cannot look: write the person first.'
                : null,
        );
    }

    /**
     * The interests this market has already had a persona drafted for.
     *
     * Read back out of the note the draft wrote. Renaming the plan — which is
     * exactly what the note asks somebody to do — must not make its interest
     * available again, or every run would re-draft the ones that were improved
     * and leave the ignored ones alone.
     *
     * @return Collection<string, int>
     */
    private function interestsAlreadyPlanned(Market $market): Collection
    {
        return CovePlan::query()
            ->where('market', $market->value)
            ->where('kind', CoveKind::Persona->value)
            ->where('note', 'like', self::INTEREST_MARK.'%')
            ->pluck('note')
            ->map(fn (string $note) => Str::before(Str::after($note, self::INTEREST_MARK), ')'))
            ->filter()
            ->flip();
    }

    /**
     * Fill a plan with what the builder would have chosen, and say how many.
     *
     * `$drafted` is this run's plans so far, and is what stops the highest
     * scoring seven products in the market being suggested for every one of
     * them — none of these has been built, so the rolling repeat memory in
     * `daily_picks` cannot see them, and the feature would look broken on first
     * sight.
     *
     * @param  list<CovePlan>  $drafted
     */
    private function prefill(CovePlan $plan, bool $withProducts, array $drafted): int
    {
        if (! $withProducts) {
            return 0;
        }

        $spoken = [];

        foreach ($drafted as $earlier) {
            foreach ($earlier->items as $item) {
                if ($item->group_id !== null) {
                    $spoken[] = $item->group_id;
                }
            }
        }

        $candidates = $this->builder->candidates(
            $plan,
            $plan->kind->targetItems(),
            array_values(array_unique($spoken)),
        );

        $filled = $this->curator->prefill($plan, $candidates);

        // Loaded so the next plan in this run can exclude them: `prefill` writes
        // through the relation, and an unloaded one reads back as empty.
        $plan->load('items');

        return $filled;
    }
}
