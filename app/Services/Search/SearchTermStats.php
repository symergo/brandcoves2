<?php

declare(strict_types=1);

namespace App\Services\Search;

use App\Enums\Market;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * What this market searches for: period by period, rising fastest, most recent.
 *
 * ## Why this exists
 *
 * The related-search chips under every result set were removed on 2026-09-05 for
 * cost — a trigram scan over ninety days of `search_log`, still 9.7-11.1s on a
 * cold term after it was cached, because a cache only ever spared the *second*
 * visitor. What went with them was the one thing on a results page that linked
 * anywhere other than back into itself.
 *
 * This is the replacement, and the shape is the point: one hub, a handful of
 * grouped aggregates, cached together — instead of a similarity scan under every
 * result set whose price rose with the size of the table it was scanning.
 *
 * ## The privacy floor applies to every published list
 *
 * `search_log` holds what people typed. It is aggregated by the hour with no
 * identity attached, which is what makes it non-personal — but a single unusual
 * query published on a public page can still be about one identifiable person,
 * and somebody who typed a name into a gift site did not publish it.
 * `min_volume` is what makes a listed term a *pattern* rather than an event.
 *
 * It matters most for `latest()`, which without it would be a live feed of what
 * individuals are searching for right now. With the floor it reads "terms that
 * are established *and* were searched again recently", which is a different and
 * publishable thing. Do not lower the floor to make a list longer — a short list
 * is the correct output of a quiet market.
 *
 * ## Why trending is a rate, not a count
 *
 * Ranked by count it would return the same terms as the period columns and the
 * section would be decoration. Trending compares a term's rate over the last
 * `trending_days` against its rate across the rest of the window, so a term that
 * has always sold steadily does not appear and one that has just started moving
 * does.
 *
 * The prior rate is smoothed by one search. Without it a term with no history
 * divides by zero and pins the top of the list — both arithmetically silly and
 * the noisiest possible answer, since a term with no history is precisely the
 * one we know least about.
 */
class SearchTermStats
{
    /**
     * @return array{months: list<array<string, mixed>>, trending: list<array<string, mixed>>, latest: list<array<string, mixed>>}
     */
    public function for(Market $market): array
    {
        return Cache::remember(
            'bc:search-stats:'.$market->value,
            (int) config('giftcoves.search.popular.cache_ttl'),
            fn (): array => [
                'months' => $this->periods($market),
                'trending' => $this->trending($market),
                'latest' => $this->latest($market),
            ],
        );
    }

    /**
     * One column per period, newest first, each ranked on its own.
     *
     * ## Why periods rather than one list over the whole window
     *
     * A single ranking over three months answers "what is popular here" and
     * nothing else. Three periods side by side answer "what is popular *now*,
     * and what was popular before it" — the same rows, arranged so the movement
     * between them is visible rather than asserted.
     *
     * ## Weeks by default
     *
     * `period` switches this to calendar months in one word, and months were the
     * first shape. Weeks won on the data: `search_log` held 26 days when this
     * shipped, which fills three weekly columns and leaves the third monthly one
     * empty. A column with nothing in it teaches a reader that the page is
     * broken, and a feature that cannot be seen for two months is not a feature.
     *
     * @return list<array{label: string, terms: list<array<string, mixed>>}>
     */
    private function periods(Market $market): array
    {
        $columns = (int) config('giftcoves.search.popular.columns');
        $out = [];

        for ($i = 0; $i < $columns; $i++) {
            [$start, $end] = $this->window($i);

            // The period before this one, for the direction arrows. Uncapped and
            // unfloored — see ranksIn().
            [$prevStart] = $this->window($i + 1);
            $previous = $this->ranksIn($market, $prevStart, $start);

            $terms = [];
            $rank = 0;

            foreach ($this->topIn($market, $start, $end) as $row) {
                $term = (string) $row->query;
                $rank++;

                $terms[] = [
                    'term' => $term,
                    'movement' => $this->movement($rank, $term, $previous),
                    'url' => $this->url($market, $term),
                ];
            }

            $out[] = [
                'label' => $this->label($market, $start, $end),
                'terms' => $terms,
            ];
        }

        return $out;
    }

    /**
     * The `$back`th period back, as [start, end). End is exclusive.
     *
     * @return array{Carbon, Carbon}
     */
    private function window(int $back): array
    {
        $monthly = config('giftcoves.search.popular.period') === 'month';

        $start = $monthly
            ? now()->startOfMonth()->subMonths($back)
            // Monday, because a week that starts on Sunday reads as a fortnight
            // straddling two of them to everyone in these four markets.
            : now()->startOfWeek(Carbon::MONDAY)->subWeeks($back);

        $end = $monthly ? $start->copy()->addMonth() : $start->copy()->addWeek();

        return [$start, $end];
    }

    /**
     * What the column is called, in the market's own language.
     *
     * Built here rather than in the browser: month and weekday names are the one
     * piece of copy the language files do not carry, `Intl` support in a browser
     * is not something to rely on for a heading, and the market already tells us
     * which language to format in.
     */
    private function label(Market $market, Carbon $start, Carbon $end): string
    {
        $language = $market->language();

        if (config('giftcoves.search.popular.period') === 'month') {
            return Str::ucfirst($start->locale($language)->isoFormat('MMMM YYYY'));
        }

        $last = $end->copy()->subDay();

        // "31 aug – 6 sep". The year is left off: three consecutive weeks never
        // need it to be told apart, and it is noise in a column heading.
        return $start->locale($language)->isoFormat('D MMM')
            .' – '
            .$last->locale($language)->isoFormat('D MMM');
    }

    /**
     * The base every published list narrows: this market, answerable, printable.
     *
     * Searches that found nothing are excluded throughout. They are the most
     * valuable rows in the table — they are content gaps, which is why
     * `SearchLog::record()` logs after the count is known — but they belong to
     * `TopicMiner`, not to a reader. A link to "nothing matched" is a bad link on
     * the one page whose whole job is linking outward.
     *
     * The length cap is blunt on purpose: a pasted URL the Amazon parser did not
     * claim is logged as typed, and no real search for a gift runs that long.
     */
    private function scoped(Market $market): Builder
    {
        return DB::table('search_log')
            ->where('market', $market->value)
            ->where('result_count', '>', 0)
            ->whereRaw('length(query) <= ?', [60]);
    }

    /** The published top of one period. */
    private function topIn(Market $market, Carbon $start, Carbon $end): Collection
    {
        return $this->scoped($market)
            ->where('hour_bucket', '>=', $start)
            ->where('hour_bucket', '<', $end)
            ->groupBy('query')
            ->havingRaw('sum(search_count) >= ?', [$this->floor()])
            ->select('query', DB::raw('sum(search_count)::int as volume'))
            ->orderByDesc('volume')
            // Then by term, so equal volumes do not shuffle between requests and
            // make the page look alive when nothing has changed.
            ->orderBy('query')
            ->limit((int) config('giftcoves.search.popular.limit'))
            ->get();
    }

    /**
     * Everything searched in one period, ranked, for comparison only.
     *
     * Uncapped, because a term now at 20 may have been 200th, and cutting this
     * at the same `limit` would report every such term as "new".
     *
     * ## No privacy floor here, deliberately
     *
     * `min_volume` guards what gets *published*. These ranks are never rendered —
     * only the direction derived from them is — so the floor would buy no privacy
     * and would manufacture false "New" badges: a term searched three times last
     * week and forty this week would read as new rather than as risen. Every
     * filter that shapes what may be published still applies to the lists.
     *
     * ## Null when the period is empty, rather than "everything is new"
     *
     * The oldest column has a period before it that the log may not reach, and
     * marking all twenty of its rows "New" would be a column of badges saying
     * nothing — the absence of a baseline is not evidence of novelty. Null
     * renders no indicators in that column at all, and they appear on their own
     * once there is history behind it.
     *
     * @return array<string, int>|null
     */
    private function ranksIn(Market $market, Carbon $start, Carbon $end): ?array
    {
        $rows = $this->scoped($market)
            ->where('hour_bucket', '>=', $start)
            ->where('hour_bucket', '<', $end)
            ->groupBy('query')
            ->select('query', DB::raw('sum(search_count)::int as volume'))
            ->orderByDesc('volume')
            ->orderBy('query')
            ->pluck('volume', 'query');

        if ($rows->isEmpty()) {
            return null;
        }

        $ranks = [];
        $rank = 0;

        foreach ($rows as $term => $volume) {
            $ranks[(string) $term] = ++$rank;
        }

        return $ranks;
    }

    /**
     * Where this term stood in the period before, as a direction.
     *
     * A row's position in its column *is* its rank for that period — the list is
     * ordered by volume and cut from the top — which is what makes comparing it
     * against an uncapped previous ranking meaningful. A term that was 45th and
     * is now 20th has moved up.
     *
     * @param  array<string, int>|null  $previous
     */
    private function movement(int $rank, string $term, ?array $previous): ?string
    {
        // Nothing to compare against: see ranksIn().
        if ($previous === null) {
            return null;
        }

        if (! isset($previous[$term])) {
            return 'new';
        }

        return match (true) {
            $rank < $previous[$term] => 'up',
            $rank > $previous[$term] => 'down',
            default => 'same',
        };
    }

    /**
     * Rising fastest, measured as a rate against the term's own baseline.
     *
     * Two queries rather than one with a conditional sum: the totals and the
     * recent slice are the same shape over different windows, and doing it this
     * way keeps every value a bound parameter instead of threading select
     * bindings and having bindings past each other in one statement.
     *
     * @return list<array{term: string, volume: int, lift: float, url: string}>
     */
    private function trending(Market $market): array
    {
        $recentDays = (int) config('giftcoves.search.popular.trending_days');
        $priorDays = max(1, $this->days() - $recentDays);

        $totals = $this->scoped($market)
            ->where('hour_bucket', '>=', now()->subDays($this->days()))
            ->groupBy('query')
            ->havingRaw('sum(search_count) >= ?', [$this->floor()])
            ->select('query', DB::raw('sum(search_count)::int as volume'))
            ->pluck('volume', 'query');

        if ($totals->isEmpty()) {
            return [];
        }

        $recent = $this->scoped($market)
            ->where('hour_bucket', '>=', now()->subDays($recentDays))
            ->whereIn('query', $totals->keys()->all())
            ->groupBy('query')
            ->select('query', DB::raw('sum(search_count)::int as volume'))
            ->pluck('volume', 'query');

        $scored = [];

        foreach ($totals as $term => $volume) {
            $now = (int) ($recent[$term] ?? 0);

            // Nothing in the recent window is not a rise, however popular.
            if ($now === 0) {
                continue;
            }

            $prior = max(0, (int) $volume - $now);

            $scored[] = [
                'term' => (string) $term,
                'url' => $this->url($market, (string) $term),
                // Ordering only, and stripped before the payload leaves here —
                // see the note on counts in the class docblock.
                'lift' => round(($now / $recentDays) / (($prior + 1) / $priorDays), 2),
                'volume' => (int) $volume,
            ];
        }

        // Volume breaks a tie, so two terms rising at the same rate are ordered
        // by the one carrying more evidence.
        usort($scored, fn (array $a, array $b) => [$b['lift'], $b['volume']] <=> [$a['lift'], $a['volume']]);

        return array_map(
            fn (array $row) => ['term' => $row['term'], 'url' => $row['url']],
            array_slice($scored, 0, (int) config('giftcoves.search.popular.short_list')),
        );
    }

    /**
     * Recently active, not recently typed.
     *
     * The floor is the whole reason this list is publishable at all — see the
     * class docblock.
     *
     * @return list<array{term: string, volume: int, url: string}>
     */
    private function latest(Market $market): array
    {
        return $this->scoped($market)
            ->where('hour_bucket', '>=', now()->subDays($this->days()))
            ->groupBy('query')
            ->havingRaw('sum(search_count) >= ?', [$this->floor()])
            ->select(
                'query',
                DB::raw('sum(search_count)::int as volume'),
                DB::raw('max(hour_bucket) as last_seen'),
            )
            ->orderByDesc('last_seen')
            ->orderBy('query')
            ->limit((int) config('giftcoves.search.popular.short_list'))
            ->get()
            ->map(fn ($r) => [
                'term' => (string) $r->query,
                'url' => $this->url($market, (string) $r->query),
            ])
            ->all();
    }

    private function days(): int
    {
        return (int) config('giftcoves.search.popular.window_days');
    }

    private function floor(): int
    {
        return (int) config('giftcoves.search.popular.min_volume');
    }

    private function url(Market $market, string $term): string
    {
        return '/'.$market->value.'/search?'.http_build_query(['q' => $term]);
    }
}
