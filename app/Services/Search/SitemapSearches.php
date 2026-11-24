<?php

declare(strict_types=1);

namespace App\Services\Search;

use App\Enums\Market;
use Illuminate\Http\Request;

/**
 * The search pages a market's sitemap submits: the "latest" column of the
 * popular-searches page (SearchTermStats), at their canonical /{market}/zoek/{term}
 * address. One place, because three things ask: the sitemap lists them, the page
 * cache keeps them a day for search engines, and bc:warm-sitemap-searches fills
 * that cache (owner, 2026-11-24: "let's pre-cache pages in the sitemap").
 *
 * A read of SearchTermStats' cached lists, so asking costs nothing per request.
 */
final class SitemapSearches
{
    /** @return list<string> paths, such as /be-nl/zoek/lego */
    public static function paths(Market $market): array
    {
        // A market kept from search engines (Spain since 2026-11-22) has no sitemap.
        if (! $market->isIndexable()) {
            return [];
        }

        return collect(app(SearchTermStats::class)->for($market)['latest'])
            ->pluck('url')
            ->filter(fn ($url) => is_string($url) && $url !== '')
            ->unique()
            ->values()
            ->all();
    }

    /** Whether this request is for one of the market's submitted search pages. */
    public static function includes(Request $request, Market $market): bool
    {
        return in_array('/'.ltrim($request->path(), '/'), self::paths($market), true);
    }
}
