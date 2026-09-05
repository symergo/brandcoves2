<?php

declare(strict_types=1);

namespace App\Services\Seo;

use App\Enums\Market;
use App\Models\SearchLog;
use Illuminate\Support\Facades\Cache;

/**
 * Other searches people ran here.
 *
 * The internal-linking half of a results page, and the only part of it that is
 * not about the current page. A page with no outbound links is a leaf; a crawler
 * that reaches a leaf stops.
 *
 * From our own log, so these are real searches with real results rather than a
 * keyword tool's guesses — and they are the demand signal no competitor can see.
 * Trigram similarity finds the neighbours: "koptelefoon" pulls "draadloze
 * koptelefoon" and "gaming koptelefoon" without anybody maintaining a taxonomy.
 *
 * Moved out of `PageNarrative` when the page copy became editable. It stays in
 * `Seo` and stays a query, because that is what it is; the thing an editor can
 * place on a page is `App\Services\Pages\Placeholders\RelatedSearches`, which
 * calls this.
 */
class RelatedSearchQuery
{
    /** @return list<array{label: string, url: string}> */
    public function for(string $term, Market $market, int $limit = 8): array
    {
        $needle = trim(mb_strtolower($term));

        if ($needle === '') {
            return [];
        }

        /*
         * Cached, for the same reason the facet counts beside it are, and it
         * turned out to matter considerably more.
         *
         * The scan below is a trigram lookup over ninety days of `search_log`,
         * and its cost grows with that table rather than with the catalogue —
         * `search_log` grows with traffic, so this is a query whose price rises
         * with the site's own success. Measured on production 2026-09-04, the
         * canonical search page took 6.8-8.0s and a brand page up to 5.0s, while
         * staging on the identical commit served the same terms in 0.5s. The
         * isolating measurement is `?q=watch` at 7.9s against `?q=watch&min=1` at
         * 0.6s: a EUR0.01 floor that excludes nothing, the same 3042 results, but
         * any filter trips `isThin()` and skips the copy block whole.
         *
         * An hour, rather than the facets' five minutes, because the two age
         * differently: a facet count trails a live offer fold within one search,
         * while these neighbours are drawn from a ninety-day window and a new one
         * has to out-rank a quarter of accumulated volume before it appears. The
         * cost of the staleness is a chip row that takes up to an hour to notice a
         * newly popular term, on a block that is decoration rather than
         * navigation.
         *
         * The empty answer is cached too, deliberately. A term with no neighbours
         * is the common case for the long tail, and re-running the scan to
         * rediscover nothing is exactly the work worth skipping.
         */
        return Cache::remember(
            'bc:search:related:'.$market->value.':'.$limit.':'.sha1($needle),
            (int) config('giftcoves.search.related_cache_ttl'),
            fn (): array => $this->neighbours($needle, $market, $limit),
        );
    }

    /**
     * The scan itself, uncached.
     *
     * @return list<array{label: string, url: string}>
     */
    private function neighbours(string $needle, Market $market, int $limit): array
    {
        return SearchLog::query()
            ->where('market', $market->value)
            ->where('hour_bucket', '>=', now()->subDays(90))
            ->whereRaw('lower(query) <> ?', [$needle])
            // `<%` (word_similarity), never `%`. Measured on this catalogue:
            // similarity() compares whole strings and scores a realistic
            // neighbour under the 0.3 default, so `%` finds nothing.
            ->whereRaw('? <% query', [$needle])
            /*
             * Held at 0.6 explicitly.
             *
             * `<%` compares against pg_trgm.word_similarity_threshold, which
             * search now sets to 0.45 on every connection so its own `<%` can
             * use the trigram index. These chips were written against Postgres'
             * 0.6 default and would have widened as a side effect.
             *
             * Wrong for the same reason as in SpectrumRetriever, and more
             * publicly: these render as links on an indexable page. A loose
             * neighbour is not a forgiving typo, it is a link promising
             * something the target page does not answer.
             */
            ->whereRaw('word_similarity(?, query) >= ?', [
                $needle,
                (float) config('giftcoves.search.trigram_threshold_strict'),
            ])
            ->where('result_count', '>', 0)
            ->groupBy('query')
            ->orderByRaw('sum(search_count) desc')
            ->limit($limit)
            ->pluck('query')
            ->map(fn (string $query) => [
                'label' => $query,
                'url' => '/'.$market->value.'/search?'.http_build_query(['q' => $query]),
            ])
            ->values()
            ->all();
    }
}
