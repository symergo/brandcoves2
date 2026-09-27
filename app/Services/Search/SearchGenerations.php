<?php

declare(strict_types=1);

namespace App\Services\Search;

use App\Enums\Market;
use Illuminate\Support\Facades\Cache;

/**
 * The numbers that retire cached search results (owner's decision, 2026-09-27).
 *
 * Result ids and facets are kept 12 hours, and every key carries these
 * generations. Bumping one makes every key built with the old number
 * unreachable at once, without finding or deleting anything: the old entries
 * simply expire unread. That is also what makes it race-free — a request that
 * read the old number and writes its answer late writes under a key nobody
 * asks for any more.
 *
 * Three of them, for the only moments the stored catalogue changes under a
 * search:
 *
 * - **market**: grouping finished for a market (the twice-daily catalogue
 *   update), a source was withdrawn, or an editor merged or split products.
 *   Everything cached for the market goes.
 * - **term**: a queued live fetch (PullLiveSearch) finished folding what the
 *   shops said about a term. Every filter and sort of that term goes.
 * - **brand**: the same fetch on a brand page, which asks the shops for the
 *   brand's name while its sub-searches (`?q=koptelefoon` on the brand page)
 *   ask nobody. Keyed on the brand's spellings, so the page and every
 *   sub-search of it go together.
 *
 * Plain ints, stored forever: the cache store rebuilds no objects, and a
 * generation that expired would restart at zero and could meet old entries.
 */
final class SearchGenerations
{
    public static function market(Market $market): int
    {
        return (int) Cache::get(self::marketKey($market), 0);
    }

    public static function term(Market $market, string $term): int
    {
        $term = self::normalise($term);

        return $term === '' ? 0 : (int) Cache::get(self::termKey($market, $term), 0);
    }

    /** @param  list<string>  $brands */
    public static function brands(Market $market, array $brands): int
    {
        return $brands === [] ? 0 : (int) Cache::get(self::brandKey($market, $brands), 0);
    }

    public static function bumpMarket(Market $market): void
    {
        self::bump(self::marketKey($market));
    }

    public static function bumpTerm(Market $market, string $term): void
    {
        $term = self::normalise($term);

        if ($term !== '') {
            self::bump(self::termKey($market, $term));
        }
    }

    /** @param  list<string>  $brands */
    public static function bumpBrands(Market $market, array $brands): void
    {
        if ($brands !== []) {
            self::bump(self::brandKey($market, $brands));
        }
    }

    private static function bump(string $key): void
    {
        // add() first: incrementing a missing key is not the same on every
        // store, and forever so a generation never falls back to zero.
        Cache::add($key, 0);
        Cache::increment($key);
    }

    private static function marketKey(Market $market): string
    {
        return 'bc:search:gen:'.$market->value;
    }

    private static function termKey(Market $market, string $normalised): string
    {
        return 'bc:search:termgen:'.$market->value.':'.sha1($normalised);
    }

    /** @param  list<string>  $brands */
    private static function brandKey(Market $market, array $brands): string
    {
        sort($brands);

        return 'bc:search:brandgen:'.$market->value.':'.sha1(json_encode($brands, JSON_THROW_ON_ERROR));
    }

    private static function normalise(string $term): string
    {
        return mb_strtolower(trim($term));
    }
}
