<?php

declare(strict_types=1);

namespace App\Services\Search;

use App\Services\Catalogue\ProductSignals;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * One line above the results about what people keep for this search term:
 * "People keep 38 products matching “koffie” on their lists".
 *
 * The owner's request of 2026-09-26. Counted over distinct products and
 * distinct people (a signed-in owner or an anonymous one), across every
 * product whose words match the term in this market, whatever filters the page
 * has on: the line is about the words, not the filtered page. See
 * docs/features/search.md, "What other people keep".
 *
 * Null unless the people behind the count reach the threshold the per-card
 * counts use ({@see ProductSignals::searchThreshold()}), so a rare term cannot
 * point at the one person who keeps it. Counts only: never a claim (invariant
 * 4), never a list, a name or who. An unaccepted suggestion is not a save.
 */
class SearchSignals
{
    /** Ten minutes: the line moves slowly and a popular term is one query. */
    private const TTL = 600;

    public function __construct(private readonly SearchService $search) {}

    /**
     * `lists` since 2026-10-05: the line now says how many lists the term is on
     * (owner: '"stoomreiniger" staat op 2 lijsten'), and `products` counted
     * something else, so it could not stand in for it.
     *
     * @return array{products: int, lists: int, people: int}|null
     */
    public function summary(SearchQuery $query): ?array
    {
        if (! $query->hasTerm()) {
            return null;
        }

        $counts = Cache::remember(
            // v2: the cached array gained `lists`; an old entry would lack it.
            'search-signals-v2:'.$query->market->value.':'.md5(mb_strtolower(trim($query->term))),
            self::TTL,
            function () use ($query): array {
                $row = DB::table('wishlist_items')
                    ->join('wishlists', 'wishlists.id', '=', 'wishlist_items.wishlist_id')
                    ->whereNotNull('wishlist_items.accepted_at')
                    ->whereIn('wishlist_items.group_id', $this->search->termMatches($query))
                    ->selectRaw('count(DISTINCT wishlist_items.group_id) AS products')
                    ->selectRaw('count(DISTINCT wishlist_items.wishlist_id) AS lists')
                    ->selectRaw("count(DISTINCT COALESCE('u' || wishlists.owner_user_id::text, 'a' || wishlists.owner_anon_id::text)) AS people")
                    ->first();

                return [
                    'products' => (int) ($row->products ?? 0),
                    'lists' => (int) ($row->lists ?? 0),
                    'people' => (int) ($row->people ?? 0),
                ];
            },
        );

        if ($counts['products'] === 0 || $counts['people'] < ProductSignals::searchThreshold()) {
            return null;
        }

        return $counts;
    }
}
