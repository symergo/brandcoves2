<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\Search\SearchTermStats;
use App\Services\Seo\PageMeta;
use App\Support\CurrentMarket;
use Inertia\Inertia;
use Inertia\Response;

/**
 * What people search for here.
 *
 * The internal-linking hub that replaced the related-search chips — see
 * {@see SearchTermStats} for why one page beats a row under every result set.
 *
 * ## Indexable, and its links are followed
 *
 * Unlike the chips it replaces, which were `nofollow`ed and then removed. The
 * difference is what the links point at: a chip row on a search page offered
 * terms *derived from that page*, so crawling one minted a query string nobody
 * had ever typed, which was logged and became another chip. Every term here has
 * already been searched by real people — following them mints nothing new, and
 * the set is bounded by what is in the log rather than by the crawler's appetite.
 *
 * ## Empty is a valid page
 *
 * A new market has no search history, and the honest answer is a sentence saying
 * so rather than an empty table or a 404. The footer links here from every page
 * in every market, including the ones that opened yesterday.
 */
class PopularSearchesController extends Controller
{
    public function __invoke(CurrentMarket $current, SearchTermStats $stats): Response
    {
        $lists = $stats->for($current->get());

        // A column with a heading and no rows still counts as nothing to show:
        // the periods always exist, only their contents vary.
        $hasColumns = collect($lists['months'])->contains(fn (array $c) => $c['terms'] !== []);
        $empty = ! $hasColumns && $lists['trending'] === [] && $lists['latest'] === [];

        app(PageMeta::class)->set(
            title: __('site.popular_searches.seo_title'),
            description: __('site.popular_searches.seo_description'),
            canonical: url($current->url('popular-searches')),
            /*
             * Indexable when it has something to show. An empty one is a real
             * page for a visitor and a thin one for a crawler, and thin pages
             * spend crawl budget that belongs to products and guides — the same
             * rule the filtered search variants follow.
             */
            robots: $empty ? 'noindex, follow' : null,
        );

        return Inertia::render('PopularSearches', [
            'months' => $lists['months'],
            'trending' => $lists['trending'],
            'latest' => $lists['latest'],
            'urls' => [
                'search' => $current->url('search'),
                'searchHelp' => $current->url('search-help'),
            ],
        ]);
    }
}
