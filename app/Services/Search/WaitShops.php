<?php

declare(strict_types=1);

namespace App\Services\Search;

use App\Enums\Market;
use App\Models\Merchant;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * The shops a market's catalogue holds most products from, by name, for the
 * line under the search bar while a search loads ("Even rondneuzen bij
 * Coolblue…", owner, 2026-10-25). True names: these are the shops the
 * search actually looks through.
 *
 * Counted on a 1% sample of the offers (`TABLESAMPLE SYSTEM`): the order of
 * the big shops is all this needs, and counting every offer of a market
 * (1.6 million on be-nl) per day would cost seconds for a joke. Kept a day.
 */
final class WaitShops
{
    /** How many shops the line picks from. */
    private const COUNT = 8;

    /** @return list<string> */
    public static function for(Market $market): array
    {
        return Cache::remember('bc:search:wait-shops:'.$market->value, now()->addDay(), function () use ($market): array {
            $ids = DB::table(DB::raw('products TABLESAMPLE SYSTEM (1)'))
                ->where('market', $market->value)
                ->where('status', 'active')
                ->whereNotNull('merchant_id')
                ->groupBy('merchant_id')
                ->orderByRaw('count(*) desc')
                ->limit(self::COUNT)
                ->pluck('merchant_id')
                ->all();

            $shops = Merchant::query()->whereIn('id', $ids)->get()->keyBy('id');

            return array_values(array_unique(array_filter(array_map(
                fn ($id) => $shops->get($id)?->displayName(),
                $ids,
            ))));
        });
    }
}
