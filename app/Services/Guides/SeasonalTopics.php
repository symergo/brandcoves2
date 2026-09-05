<?php

declare(strict_types=1);

namespace App\Services\Guides;

use App\Enums\Market;
use App\Models\GuideTopic;
use App\Models\ProductGroup;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Cove topics that come from the calendar rather than the search log.
 *
 * `TopicMiner` is the primary source and should stay that way — our own searches
 * are real demand no competitor can see. This fills its one structural blind
 * spot: **the log cannot know about a season before the season arrives.**
 *
 * Barbecue searches peak in June. A miner reading June's log commissions the
 * barbecue Cove in July and it first earns real traffic the following May.
 * Halloween is worse: the whole demand window is three weeks, so by the time it
 * shows in the log it is over.
 *
 * So each seasonal topic carries a window that opens well before its season, and
 * `TopicMiner::ripest()` prefers an in-season topic over a higher-scoring
 * evergreen one. Out of season these rows are inert.
 *
 * ## What it does not do
 *
 * It does not fabricate a search volume. A seasonal topic's `search_volume` is
 * whatever the log actually says — usually zero on a young site — and the
 * seasonal branch of `ripest()` deliberately does not test it. Writing a
 * plausible-looking number into that column would corrupt the one honest demand
 * signal the system has, and the "180 searches, 0 products" report in admin is
 * only useful while every number in it is measured.
 */
class SeasonalTopics
{
    /**
     * A Cove needs products. Same threshold as the miner: below five it is a list
     * with gaps, and a "best X" page with three entries reads as thin.
     */
    private const MIN_PRODUCTS = 5;

    /**
     * Write or refresh the seasonal rows for a market.
     *
     * Idempotent, and it never overwrites a decision a human has made — a topic
     * an editor rejected stays rejected, exactly as in the miner.
     *
     * @return int how many rows are currently in season
     */
    public function seed(Market $market, ?CarbonImmutable $today = null): int
    {
        $today ??= CarbonImmutable::today();
        $inSeason = 0;

        foreach ((array) config('cove_seasons', []) as $entry) {
            if (! is_array($entry) || ! isset($entry['topic'])) {
                continue;
            }

            $markets = (array) ($entry['markets'] ?? ['*']);

            if (! in_array('*', $markets, true) && ! in_array($market->value, $markets, true)) {
                continue;
            }

            $window = (array) ($entry['window'] ?? []);
            $topic = (string) $entry['topic'];
            $queries = $this->queriesFor($entry, $market);

            $existing = GuideTopic::query()
                ->where('market', $market->value)
                ->where('topic', $topic)
                ->first();

            // A human already decided about this topic. The window is still worth
            // attaching — it costs nothing and makes the row explicable — but the
            // status is theirs.
            $decided = $existing !== null
                && in_array($existing->status, ['queued', 'rejected', 'published'], true);

            GuideTopic::updateOrCreate(
                ['market' => $market->value, 'topic' => $topic],
                array_merge([
                    'origin' => 'seasonal',
                    'season_from' => $window['from'] ?? null,
                    'season_to' => $window['to'] ?? null,
                    /*
                     * Merged, not replaced. A seasonal topic frequently collides
                     * with one the miner already found — which is the best
                     * possible outcome, since it means real demand exists for a
                     * season we already knew was coming. Overwriting would throw
                     * away the queries people actually typed.
                     */
                    'member_queries' => array_values(array_unique([
                        ...$queries,
                        ...(array) ($existing->member_queries ?? []),
                    ])),
                    'available_products' => $this->availableProducts($market, $queries),
                ], $decided ? [] : ['status' => 'candidate']),
            );

            if ($this->inWindow($today, $window)) {
                $inSeason++;
            }
        }

        return $inSeason;
    }

    /**
     * The in-season seasonal topic most worth writing next.
     *
     * Ordered by how soon the window closes, not by score: a Halloween Cove
     * written on 20 October is nearly worthless and the same Cove written on
     * 1 August is an asset for a decade. Urgency beats size here in a way it
     * never does for an evergreen topic.
     */
    public function ripest(Market $market, ?CarbonImmutable $today = null): ?GuideTopic
    {
        $today ??= CarbonImmutable::today();

        $candidates = GuideTopic::query()
            ->where('market', $market->value)
            ->where('origin', 'seasonal')
            ->whereIn('status', ['candidate', 'queued'])
            /*
             * Not yet turned into a plan.
             *
             * Was `whereNull('guide_id')`, an FK into the `guides` table the fold
             * retired. `TopicPlanner` sets `plan_id` when a topic becomes a draft
             * plan, so that is the column that means "nobody has taken this one".
             */
            ->whereNull('plan_id')
            ->where('available_products', '>=', self::MIN_PRODUCTS)
            ->whereNotNull('season_from')
            ->notRecentlyAttempted()
            ->get();

        $open = $candidates
            ->filter(fn (GuideTopic $topic) => $this->inWindow($today, [
                'from' => $topic->season_from,
                'to' => $topic->season_to,
            ]))
            ->sortBy(fn (GuideTopic $topic) => $this->daysLeft($today, (string) $topic->season_to));

        return $open->first();
    }

    /**
     * Every season whose window is open, or opens soon — planned or not.
     *
     * `ripest()` answers a different question — "which single season is most
     * urgent" — and it was the only way to reach these rows, which suited a
     * pipeline that wrote one guide at a time. A recurring editorial calendar
     * needs the other question: which seasons should be on it, given how far
     * ahead it is being drawn.
     *
     * **Planned ones included, deliberately.** This filtered on `plan_id IS
     * NULL` while a season was something that happened once, and that is exactly
     * what stopped the calendar repeating: `kamperen` was laid out one spring
     * and never offered again, so the pages it produced were never refreshed
     * and a subject the catalogue could not fill that year was never
     * reconsidered. `SeasonalSeries::plan()` reads the difference and either
     * lays the season out or renews it.
     *
     * Everything except `rejected`, which is a person's decision and the one
     * status a yearly pass must not overturn.
     *
     * Ordered by the day each window opens inside the range, so a command that
     * takes the first few takes the most urgent few — and so a run that is
     * interrupted has done the work that mattered most.
     *
     * `available_products` is the same floor `ripest()` applies. It is a rough
     * ILIKE count and the real test is whether the ladder can fill a part, which
     * `SeasonalSeries` does properly; this only keeps the obviously empty ones
     * out of a loop that would otherwise probe every facet of every season in
     * five markets. A season that has fallen below it keeps the pages it already
     * has — they are simply not refreshed this year, which is a better outcome
     * for an evergreen page than being rebuilt from a catalogue that cannot
     * fill it.
     *
     * @return Collection<int, GuideTopic>
     */
    public function opening(Market $market, CarbonImmutable $from, int $days): Collection
    {
        return GuideTopic::query()
            ->where('market', $market->value)
            ->where('origin', 'seasonal')
            ->where('status', '!=', 'rejected')
            ->where('available_products', '>=', self::MIN_PRODUCTS)
            ->whereNotNull('season_from')
            ->notRecentlyAttempted()
            ->get()
            ->map(function (GuideTopic $topic) use ($from, $days): array {
                return ['topic' => $topic, 'opens' => $this->opensWithin($topic, $from, $days)];
            })
            ->filter(fn (array $row) => $row['opens'] !== null)
            ->sortBy('opens')
            ->map(fn (array $row) => $row['topic'])
            ->values();
    }

    /**
     * How many days from `$from` until this season is in window, within range.
     *
     * Null when it never is. Walked a day at a time rather than parsed into a
     * date, because the window is `MM-DD` and may wrap the year — Valentine's
     * runs from 27 December — and the walk gets both the wrap and the "already
     * open" case right without a second code path. A few hundred string
     * comparisons per market, once, in a command that then does real work.
     */
    private function opensWithin(GuideTopic $topic, CarbonImmutable $from, int $days): ?int
    {
        $window = ['from' => $topic->season_from, 'to' => $topic->season_to];

        for ($day = 0; $day <= $days; $day++) {
            if ($this->inWindow($from->addDays($day), $window)) {
                return $day;
            }
        }

        return null;
    }

    /**
     * The topic's product words, in the market's language.
     *
     * ## Why these have to be translated at all
     *
     * A query here is matched against product titles, and a title is written in
     * the market's language. "tent" and "slaapzak" find nothing in a French
     * catalogue, so every seasonal topic in be-fr reported zero products and the
     * whole feature was silently inert outside the Dutch markets — visible in
     * admin as 23 topics that never ripen, which reads as "no demand" rather than
     * "wrong words".
     *
     * `queries` may be a flat list (all markets, the original shape) or a map of
     * language => list. A market with no entry falls back to English rather than
     * to nothing: an English word in a French catalogue matches the loan words
     * ("barbecue", "camping") and misses the rest, which is a thinner topic
     * rather than an absent one.
     *
     * @param  array<string, mixed>  $entry
     * @return list<string>
     */
    private function queriesFor(array $entry, Market $market): array
    {
        $queries = $entry['queries'] ?? [];

        if (! is_array($queries) || $queries === []) {
            return [];
        }

        // A flat list — every value is a string, so there is nothing to resolve.
        if (array_is_list($queries)) {
            return array_values(array_map('strval', $queries));
        }

        $language = $market->language();
        $chosen = $queries[$language] ?? $queries['en'] ?? [];

        return array_values(array_map('strval', (array) $chosen));
    }

    /**
     * How many in-stock groups the topic can draw on.
     *
     * A LIKE per query rather than a tsquery: this runs a couple of dozen times a
     * night and the point is a rough floor, not a ranking. `TopicMiner` uses the
     * index properly for the topics it actually builds.
     *
     * @param  list<string>  $queries
     */
    private function availableProducts(Market $market, array $queries): int
    {
        if ($queries === []) {
            return 0;
        }

        return ProductGroup::query()
            ->forMarket($market)
            ->presentable()
            ->where(function ($q) use ($queries): void {
                foreach ($queries as $query) {
                    // The head noun, so "opblaasbaar zwembad" also matches
                    // "zwembad" — the whole phrase rarely appears in a title.
                    $head = (string) (explode(' ', trim($query))[count(explode(' ', trim($query))) - 1] ?? '');

                    if ($head !== '') {
                        $q->orWhere('title', 'ilike', '%'.$head.'%');
                    }
                }
            })
            ->count();
    }

    /**
     * Is the date inside a MM-DD window?
     *
     * String comparison, which works because MM-DD sorts chronologically. A window
     * whose end precedes its start wraps the year — Valentine's runs from
     * 27 December and would otherwise be empty.
     *
     * @param  array<string, mixed>  $window
     */
    private function inWindow(CarbonImmutable $date, array $window): bool
    {
        $from = (string) ($window['from'] ?? '');
        $to = (string) ($window['to'] ?? '');

        if ($from === '' || $to === '') {
            return false;
        }

        $today = $date->format('m-d');

        return $from <= $to
            ? $today >= $from && $today <= $to
            : $today >= $from || $today <= $to;
    }

    /** Days until the window closes, for ordering by urgency. */
    private function daysLeft(CarbonImmutable $today, string $to): int
    {
        if ($to === '') {
            return 999;
        }

        [$month, $day] = array_map('intval', explode('-', $to) + [1, 1]);
        $close = CarbonImmutable::create($today->year, $month, $day);

        // A window closing "in January" from a December date closes next year.
        if ($close->lessThan($today)) {
            $close = $close->addYear();
        }

        return (int) $today->diffInDays($close);
    }
}
