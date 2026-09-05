<?php

declare(strict_types=1);

namespace App\Services\Search;

use App\Enums\Market;
use App\Models\ProductGroup;
use App\Models\SearchLog;
use Illuminate\Support\Facades\Cache;

/**
 * What people have been searching for, with something to look at.
 *
 * `search_log` records queries, never the products a query returned, so
 * "recently searched products" cannot be read out of a table. It has to be
 * resolved by running the searches again — which is precisely why this is
 * computed on a schedule and cached rather than done in the request.
 *
 * The homepage had three `COUNT(*)` queries removed from it for being too
 * expensive to justify (see homepage.md); putting six full searches on the same
 * page would be considerably worse. Every other discovery surface on this site
 * is precomputed for the same reason.
 */
final class RecentSearches
{
    /**
     * Slightly longer than the refresh interval.
     *
     * The job runs hourly, so a 75-minute life means one failed run degrades to
     * a slightly stale band rather than an empty one. The band is a nicety; it
     * must never be the reason the front page looks broken.
     */
    private const TTL = 75 * 60;

    /**
     * Three, so the band is one row and not a grid.
     *
     * It was six, which wrapped to a second row and turned a glance at what
     * other people are looking for into a block of the front page competing with
     * the editorial below it. Three is a sample; six starts to read as a ranking.
     */
    private const TERMS = 3;

    private const IMAGES_PER_TERM = 4;

    public function __construct(private readonly SearchService $search) {}

    /**
     * Read-side. Empty until the first refresh, and empty is a valid answer —
     * a new market has no search history and the band simply does not render.
     *
     * @return list<array{term: string, url: string, images: list<string>}>
     */
    public function for(Market $market): array
    {
        // Trimmed on the way out as well as on the way in. The cache holds
        // whatever the last refresh wrote, so lowering TERMS would otherwise
        // keep showing the old count for up to 75 minutes after a deploy.
        return array_slice(Cache::get($this->key($market), []), 0, self::TERMS);
    }

    /**
     * @return list<array{term: string, url: string, images: list<string>}>
     */
    public function refresh(Market $market): array
    {
        /*
         * Recent, deduplicated, and only searches that found something.
         *
         * `search_log` is bucketed per clock-hour, so the same term appears
         * once per hour it was searched in — pulling a wider window and then
         * uniquing is what stops one popular term filling the whole band.
         *
         * `result_count > 0` matters twice: a term that found nothing has no
         * image to show, and `zero_result_count` exists precisely because those
         * terms are a content gap rather than a recommendation.
         */
        $terms = SearchLog::query()
            ->where('market', $market->value)
            ->where('result_count', '>', 0)
            ->orderByDesc('hour_bucket')
            ->limit(60)
            ->pluck('query')
            ->map(fn (string $q) => SearchLog::normalise($q))
            ->filter(fn (string $q) => $q !== '')
            ->unique()
            ->take(self::TERMS);

        $rows = [];

        foreach ($terms as $term) {
            $images = $this->imagesFor($market, $term);

            // A term with nothing to show is left out rather than rendered as
            // an empty card. The band is images; a card without one is a hole.
            if ($images === []) {
                continue;
            }

            $rows[] = [
                'term' => $term,
                'url' => '/'.$market->value.'/search?q='.rawurlencode($term),
                'images' => $images,
            ];
        }

        Cache::put($this->key($market), $rows, self::TTL);

        return $rows;
    }

    /**
     * @return list<string>
     */
    private function imagesFor(Market $market, string $term): array
    {
        $result = $this->search->search(new SearchQuery(
            market: $market,
            term: $term,

            // Everything else on the front page is in stock, for the same
            // reason: an unbuyable product is a worse first impression than one
            // fewer picture.
            inStockOnly: true,

            // The search box does not filter to discounts, and neither should
            // the record of what the search box was asked for.
            discountedOnly: false,

            /*
             * The one line that would be a bug if it were missing.
             *
             * `search_log` feeds the demand signal that decides which guides
             * get written. This job *reads* that table and would otherwise
             * write to it on every run — six terms an hour, for ever, making
             * the most-searched terms the ones this job happened to pick up
             * first. A feedback loop that looks exactly like real demand.
             */
            logged: false,
        ));

        return collect($result->groups->items())
            /*
             * Stored groups only, which is also invariant #6 holding.
             *
             * A live Amazon result has no row in `product_groups`, so it has no
             * id here — and caching its image for an hour would be mirroring
             * catalogue data, which the Associates terms do not permit. The
             * filter is written as "must be a stored group" rather than "must
             * not be Amazon" so it stays correct for any future live source.
             */
            ->filter(fn (ProductGroup $group) => $group->exists && $group->image_url !== null)
            ->take(self::IMAGES_PER_TERM)
            ->map(fn (ProductGroup $group) => (string) $group->image_url)
            ->values()
            ->all();
    }

    private function key(Market $market): string
    {
        return "recent-searches:{$market->value}";
    }
}
