<?php

declare(strict_types=1);

namespace App\Services\Cove;

use App\Enums\CoveKind;
use App\Enums\Market;
use App\Models\CovePlan;
use App\Models\GuideTopic;
use App\Models\ProductGroup;
use App\Models\User;
use App\Services\Cove\Selectors\LadderSelector;
use App\Services\Curation\PlanCurator;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * A season, laid out across its window as a dated series of parts.
 *
 * A seasonal topic used to become one page. `TopicPlanner` turned "kamperen"
 * into a single guide, written on whatever day the queue reached it, and that
 * page then had to carry a three-month window on its own. Two things were wrong
 * with it, and both are structural rather than a matter of writing better:
 *
 * **A season is not one subject.** The calendar entry for `kamperen` names
 * tents, sleeping bags, camping chairs and stoves. One page covering four
 * unrelated shelves is a listicle; four pages, one per shelf, are four buying
 * guides that each answer a phrase somebody actually types. The facets are
 * already in the config — they are what the shortlist is retrieved with — so
 * splitting on them costs nothing and invents nothing.
 *
 * **A season has a schedule and the calendar could not see it.** The Cove
 * planner is where editorial is decided, and it held Dailies and a pile of
 * undated ideas. A season is the one non-Daily kind that is *defined* by dates,
 * and it was the one kind whose dates appeared nowhere. Each part now carries a
 * `drop_date` inside the window, so a season reads on the planner as what it is:
 * several pieces of work, spread out, in order.
 *
 * ## Nothing is laid out that cannot publish
 *
 * Each facet is probed against the catalogue *before* any row is written, and a
 * facet that cannot reach the kind's floor never becomes a part. The alternative
 * is worse than it sounds: `buildArticle()` correctly refuses a thin shortlist,
 * so a doomed part is not a bad page — it is a plan an editor approves, watches
 * do nothing, and has no way to diagnose.
 *
 * The probe runs on an unsaved plan. `LadderSelector` reads a market, a
 * keyphrase and a query list off the model and nothing else, so there is no
 * reason to persist a row to find out whether it deserves to exist.
 *
 * ## One part is not a series
 *
 * A season whose calendar entry names a single noun, or whose other nouns are
 * all thin, produces exactly what it produced before: one plan, titled after the
 * topic, with no part number in the title or the URL. "Part 1" with no part two
 * is a promise to a reader that nothing keeps.
 */
final readonly class SeasonalSeries
{
    public function __construct(
        private LadderSelector $ladder,
        private PlanCurator $curator,
        private PlanSlugs $slugs,
    ) {}

    /**
     * Put this season on the calendar for the year it next runs in.
     *
     * The one entry point, because "lay it out" and "bring it round again" are
     * the same editorial event a year apart, and every caller wants whichever
     * one applies. A season with no plans yet is laid out; one that already has
     * them is renewed in place.
     *
     * @return list<CovePlan> the parts that changed, in order, or empty if none did
     */
    public function plan(GuideTopic $topic, ?User $author = null, ?CarbonImmutable $today = null): array
    {
        $existing = $this->parts($topic);

        return $existing === []
            ? $this->lay($topic, $author, $today)
            : $this->renew($topic, $existing, $today);
    }

    /**
     * Lay one seasonal topic out as parts, and return them in order.
     *
     * Empty when the catalogue cannot fill a single part. The topic is left
     * alone in that case — not queued, not attributed to a plan — so a season
     * that is thin in April is offered again in May rather than being quietly
     * spent on nothing.
     *
     * @return list<CovePlan>
     */
    public function lay(GuideTopic $topic, ?User $author = null, ?CarbonImmutable $today = null): array
    {
        $today ??= CarbonImmutable::today();
        $market = $topic->market;

        $shortlists = $this->shortlists($topic, $market);

        if ($shortlists === []) {
            return [];
        }

        $parts = count($shortlists);
        $dates = $this->dates($topic, $parts, $today);
        $single = $parts === 1;
        $plans = [];

        foreach ($shortlists as $index => $shortlist) {
            $part = $index + 1;
            $facet = $shortlist['facet'];

            $plan = DB::transaction(function () use (
                $topic, $market, $author, $facet, $part, $parts, $single, $dates, $index
            ): CovePlan {
                $plan = CovePlan::create([
                    'market' => $market->value,
                    'kind' => CoveKind::Seasonal->value,
                    'title' => $this->title($topic, $market, $part, $single),
                    'slug' => $this->slug($topic, $market, $part, $single),
                    /*
                     * The facet, not the season.
                     *
                     * This is the phrase the part is written to answer and the
                     * first term the ladder retrieves on — and it is the whole
                     * mechanism that makes part three about camping chairs
                     * rather than about camping again. `LadderSelector::terms()`
                     * leads with the keyphrase for exactly this reason.
                     */
                    'focus_keyphrase' => $facet,
                    'queries' => [$facet],
                    'season_from' => $topic->season_from,
                    'season_to' => $topic->season_to,
                    'drop_date' => $dates[$index]->toDateString(),
                    'series_key' => $single ? null : $topic->topic,
                    'part' => $single ? null : $part,
                    'status' => 'draft',
                    'created_by' => $author?->id,
                    'note' => $this->note($topic, $facet, $part, $parts, $single),
                ]);

                // The first part is the one the topic points at, so "has this
                // topic been planned" stays a single answerable question.
                if ($index === 0) {
                    $topic->forceFill(['plan_id' => $plan->id, 'status' => 'queued'])->save();
                }

                return $plan;
            });

            $this->curator->prefill($plan, $shortlist['groups']);

            $plans[] = $plan->refresh();
        }

        return $plans;
    }

    /**
     * Bring a season that has already run back for its next window.
     *
     * **The pages do not move.** Each existing part keeps its slug, its
     * `published_at` and the ranking it has spent a year earning; what changes
     * is its `drop_date`, which slides forward into the window that is coming.
     * `PublishDueCoves` then sees a plan whose due date is later than the date
     * it was last built for, and rebuilds it on the day — new products, newly
     * written prose, same URL.
     *
     * That is why the alternative was refused. A fresh `beste-kamperen-2028`
     * beside last year's is three pages competing for one query by 2029, and the
     * evergreen page the seasonal window exists to buy indexing time for is the
     * one that loses.
     *
     * Idempotent, and cheaply so: a season whose parts are already dated inside
     * the coming window is left completely alone, which is what the weekly
     * planner run finds every time but the first.
     *
     * @param  non-empty-list<CovePlan>  $existing
     * @return list<CovePlan>
     */
    private function renew(GuideTopic $topic, array $existing, ?CarbonImmutable $today): array
    {
        $today ??= CarbonImmutable::today();
        [$from, $to] = $this->window($topic, $today);

        if ($this->alreadyDated($existing, $from, $to)) {
            return [];
        }

        $dates = $this->dates($topic, count($existing), $today);
        $renewed = [];

        foreach ($existing as $index => $part) {
            /*
             * The date, and nothing else.
             *
             * Not the title, not the shortlist, not the status — every other
             * field on this row is somebody's editorial decision, and a yearly
             * pass that overwrote a curated shortlist would undo a year of work
             * on a schedule.
             *
             * A part an editor rejected is re-dated too. The date is a fact
             * about the calendar rather than an instruction, nothing builds a
             * rejected plan, and leaving it on last year's date would make it
             * read as permanently overdue.
             */
            $part->forceFill(['drop_date' => $dates[$index]->toDateString()])->save();

            $renewed[] = $part;
        }

        return [...$renewed, ...$this->grown($topic, $existing, $from, $to)];
    }

    /**
     * Are these parts already scheduled inside the coming window?
     *
     * A part with no date at all — an editor cleared it — counts as not dated,
     * so the next run gives it one back rather than leaving a hole in a series
     * that is otherwise scheduled.
     *
     * @param  non-empty-list<CovePlan>  $parts
     */
    private function alreadyDated(array $parts, CarbonImmutable $from, CarbonImmutable $to): bool
    {
        foreach ($parts as $part) {
            if ($part->drop_date === null) {
                return false;
            }

            $date = CarbonImmutable::instance($part->drop_date);

            if ($date->lessThan($from) || $date->greaterThan($to)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Subjects the catalogue could not fill last year and can fill now.
     *
     * A season is not a fixed size. `kamperen` names four nouns, and a market
     * whose advertisers sold no camping stoves got three parts; the year an
     * advertiser arrives the fourth is worth writing — and nothing else would
     * ever notice, because a season is only looked at when it comes round.
     *
     * **Only for a season that is already a series.** One laid out as a single
     * page has no number in its slug, and there is no way to make it part one of
     * four: renaming it changes a live URL, and leaving it unnumbered beside
     * `…-deel-2` is a series whose first part is addressed unlike the rest. It
     * stays a single page and refreshes as one.
     *
     * @param  non-empty-list<CovePlan>  $existing
     * @return list<CovePlan>
     */
    private function grown(GuideTopic $topic, array $existing, CarbonImmutable $from, CarbonImmutable $to): array
    {
        if ($existing[0]->series_key === null) {
            return [];
        }

        $spoken = [];
        $spent = [];

        foreach ($existing as $part) {
            $spoken[(string) $part->focus_keyphrase] = true;

            foreach ($part->items as $item) {
                if ($item->group_id !== null) {
                    $spent[] = $item->group_id;
                }
            }
        }

        $floor = CoveKind::Seasonal->minimumItems();
        $target = CoveKind::Seasonal->targetItems();
        $market = $topic->market;
        $number = count($existing);
        $added = [];

        foreach ($this->facets($topic) as $facet) {
            if (isset($spoken[$facet])) {
                continue;
            }

            $groups = $this->ladder->select($this->probe($market, $facet), collect(), $target, $spent);

            if (count($groups) < $floor) {
                continue;
            }

            $number++;

            $plan = CovePlan::create([
                'market' => $market->value,
                'kind' => CoveKind::Seasonal->value,
                'title' => $this->title($topic, $market, $number, single: false),
                'slug' => $this->slug($topic, $market, $number, single: false),
                'focus_keyphrase' => $facet,
                'queries' => [$facet],
                'season_from' => $topic->season_from,
                'season_to' => $topic->season_to,
                /*
                 * Scheduled after the parts that already exist, not folded into
                 * their spacing. Re-spacing the whole series to fit a newcomer
                 * would move published pages' schedules to accommodate one that
                 * has never run, and it is the least urgent thing in the season:
                 * everything else is only being refreshed.
                 */
                'drop_date' => $this->after($existing, $from, $to, count($added) + 1)->toDateString(),
                'series_key' => $topic->topic,
                'part' => $number,
                'status' => 'draft',
                'note' => "From the seasonal calendar: {$topic->topic}, in season {$topic->season_from} to "
                    ."{$topic->season_to}. Part {$number}, about '{$facet}' — a subject this market had too few "
                    .'products for when the season was first laid out. Rename it after what it is actually about '
                    .'before you approve it.',
            ]);

            $this->curator->prefill($plan, $groups);

            foreach ($groups as $group) {
                $spent[] = $group->id;
            }

            $added[] = $plan->refresh();
        }

        return $added;
    }

    /**
     * A slot for a part that arrives after the series was scheduled.
     *
     * One interval past the last existing part, clamped to the window's close: a
     * new subject still has to be published while the demand is there, and a
     * date beyond the window is a page nobody reads.
     *
     * @param  non-empty-list<CovePlan>  $existing
     */
    private function after(array $existing, CarbonImmutable $from, CarbonImmutable $to, int $nth): CarbonImmutable
    {
        $last = $from;

        foreach ($existing as $part) {
            if ($part->drop_date === null) {
                continue;
            }

            $date = CarbonImmutable::instance($part->drop_date);

            if ($date->greaterThan($last)) {
                $last = $date;
            }
        }

        $step = max(1, intdiv((int) $from->diffInDays($to), count($existing) + $nth));
        $slot = $last->addDays($step * $nth);

        return $slot->greaterThan($to) ? $to : $slot;
    }

    /**
     * This season's parts, in order.
     *
     * Found by `series_key` where there is one and by the topic's own `plan_id`
     * where there is not — a season the catalogue could fill only one subject of
     * carries neither a series key nor a part number, on purpose.
     *
     * @return list<CovePlan>
     */
    private function parts(GuideTopic $topic): array
    {
        $parts = CovePlan::query()
            ->where('market', $topic->market->value)
            ->where('kind', CoveKind::Seasonal->value)
            ->where('series_key', $topic->topic)
            ->with('items')
            ->orderBy('part')
            ->get();

        if ($parts->isNotEmpty()) {
            return $parts->values()->all();
        }

        if ($topic->plan_id === null) {
            return [];
        }

        $single = CovePlan::query()->with('items')->find($topic->plan_id);

        return $single === null ? [] : [$single];
    }

    /**
     * A shortlist per facet, in calendar order, thin facets dropped.
     *
     * The running exclusion list is what makes the parts genuinely different
     * pages. The facets overlap in the catalogue — a camping chair is findable
     * from "kamperen" and half the tents are sold in sets — and without carrying
     * the earlier parts' products forward, part two is part one under a
     * different heading.
     *
     * @return list<array{facet: string, groups: list<ProductGroup>}>
     */
    private function shortlists(GuideTopic $topic, Market $market): array
    {
        $floor = CoveKind::Seasonal->minimumItems();
        $target = CoveKind::Seasonal->targetItems();

        $shortlists = [];
        $spent = [];

        foreach ($this->facets($topic) as $facet) {
            $groups = $this->ladder->select(
                $this->probe($market, $facet),
                collect(),
                $target,
                $spent,
            );

            if (count($groups) < $floor) {
                /*
                 * Not an error, and not worth reporting. A season names its
                 * nouns in every market, and a market whose advertisers do not
                 * sell camping stoves simply gets a shorter series — which is a
                 * truer set of pages than one padded to length with whatever
                 * else happened to match.
                 */
                continue;
            }

            $shortlists[] = ['facet' => $facet, 'groups' => $groups];

            foreach ($groups as $group) {
                $spent[] = $group->id;
            }
        }

        return $shortlists;
    }

    /**
     * A plan-shaped question for the selector, never saved.
     *
     * `LadderSelector` takes a plan because that is what every other caller
     * holds; what it reads is a market and some words. Persisting a row to ask
     * "would this be a viable page" would leave a draft behind every time the
     * answer was no.
     */
    private function probe(Market $market, string $facet): CovePlan
    {
        return new CovePlan([
            'market' => $market->value,
            'kind' => CoveKind::Seasonal->value,
            'title' => $facet,
            'focus_keyphrase' => $facet,
            'queries' => [$facet],
        ]);
    }

    /**
     * The subjects this season is made of, in the order the calendar names them.
     *
     * `member_queries` holds the calendar's own nouns followed by whatever the
     * miner merged in — `SeasonalTopics` writes it in that order deliberately —
     * so taking the first few keeps the chosen subjects and drops the accidental
     * ones. See `giftcoves.seasons.max_parts` for why the cap is where it is.
     *
     * @return list<string>
     */
    private function facets(GuideTopic $topic): array
    {
        $facets = array_values(array_unique(array_filter(array_map(
            'trim',
            array_filter((array) $topic->member_queries, 'is_string'),
        ))));

        // A topic with no queries at all is still a page: the topic word is what
        // the old single-guide path retrieved on, and it beats nothing.
        if ($facets === []) {
            $facets = [$topic->topic];
        }

        return array_slice($facets, 0, max(1, (int) config('giftcoves.seasons.max_parts', 4)));
    }

    /**
     * When each part is due, spread across the window.
     *
     * Evenly at `from + k·span/n`, which puts the last part a full interval
     * before the window closes rather than on the day it does. That gap is the
     * point of the whole feature: a Halloween page published on 31 October has
     * never been crawled, and the window opens in August precisely so the pages
     * inside it have time to be indexed.
     *
     * Two adjustments, both falling out of one rule — a cursor that only ever
     * moves forward:
     *
     * - **Nothing is due in the past.** A season laid out mid-window has slots
     *   that have already gone by. Those parts are late, not cancelled, so they
     *   queue from tomorrow.
     * - **No two parts of one series share a day.** Four pages published at once
     *   is not a series, it is one long page delivered awkwardly.
     *
     * @return list<CarbonImmutable>
     */
    private function dates(GuideTopic $topic, int $parts, CarbonImmutable $today): array
    {
        [$from, $to] = $this->window($topic, $today);

        $span = max(1, (int) $from->diffInDays($to));
        $cursor = $today->addDay();
        $dates = [];

        foreach (range(0, $parts - 1) as $index) {
            $natural = $from->addDays(intdiv($index * $span, $parts));
            $date = $natural->lessThan($cursor) ? $cursor : $natural;

            $dates[] = $date;
            $cursor = $date->addDay();
        }

        return $dates;
    }

    /**
     * The window as real dates, in the year it next runs.
     *
     * `season_from` and `season_to` are `MM-DD` — the shape
     * `config/cove_seasons.php` uses, because a season repeats and pinning a
     * year to it would mean editing that file every December.
     *
     * A window whose end precedes its start wraps the year: Valentine's runs
     * from 27 December, and read literally that is an empty range.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function window(GuideTopic $topic, CarbonImmutable $today): array
    {
        $from = $this->monthDay((string) $topic->season_from, $today->year, $today);
        $to = $this->monthDay((string) $topic->season_to, $from->year, $from);

        if ($to->lessThan($from)) {
            $to = $to->addYear();
        }

        // Wholly in the past: the window closed earlier this year, so the series
        // being laid out is next year's.
        if ($to->lessThan($today)) {
            $from = $from->addYear();
            $to = $to->addYear();
        }

        return [$from, $to];
    }

    /**
     * One `MM-DD` as a date in `$year`, or a sensible fallback.
     *
     * A blank or malformed value falls back to `$default` rather than throwing.
     * These strings come from a config file a person edits, and a season that
     * lays itself out slightly wrong is recoverable where a nightly command that
     * dies on one bad row is not.
     */
    private function monthDay(string $monthDay, int $year, CarbonImmutable $default): CarbonImmutable
    {
        if (preg_match('/^(\d{2})-(\d{2})$/', $monthDay, $matches) !== 1) {
            return $default;
        }

        $date = CarbonImmutable::create($year, (int) $matches[1], (int) $matches[2]);

        return $date instanceof CarbonImmutable ? $date : $default;
    }

    private function title(GuideTopic $topic, Market $market, int $part, bool $single): string
    {
        $topicWord = Str::ucfirst($topic->topic);

        if ($single) {
            return $topicWord;
        }

        return __('site.guides.series_title', ['topic' => $topicWord, 'part' => $part], $market->language());
    }

    /**
     * The address, prefixed and numbered in the market's language.
     *
     * `beste-kamperen-deel-2`, `meilleur-camping-partie-2`. The prefix is the
     * one `TopicPlanner` has always used, so a folded guide, a singly planned
     * one and a part of a series are addressed alike — and the part word is
     * translated for the same reason the prefix is: a Dutch URL with "part" in
     * it reads as a page somebody forgot to finish.
     */
    private function slug(GuideTopic $topic, Market $market, int $part, bool $single): string
    {
        $language = $market->language();
        $base = __('site.guides.slug_prefix', [], $language).'-'.$topic->topic;

        if (! $single) {
            $base .= '-'.__('site.guides.series_slug_part', [], $language).'-'.$part;
        }

        return $this->slugs->free($market, $base);
    }

    /**
     * Why this plan exists and what it is for, for whoever opens it.
     *
     * The facet is the thing a curator most needs and the one the title
     * deliberately does not carry: "Kamperen, deel 3" says where the page sits
     * in the series and nothing about what is on it.
     */
    private function note(GuideTopic $topic, string $facet, int $part, int $parts, bool $single): string
    {
        $provenance = "From the seasonal calendar: {$topic->topic}, in season {$topic->season_from} to {$topic->season_to}.";

        if ($single) {
            return $provenance.' One subject was all the catalogue could fill here, so this season is a single page '
                .'rather than a series.';
        }

        return $provenance." Part {$part} of {$parts}, about \"{$facet}\". Rename it after what it is actually about "
            .'before you approve it — the part number says where the page sits, not what is on it.';
    }
}
