<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\CoveKind;
use App\Enums\Market;
use App\Enums\PublishStatus;
use App\Services\Search\SearchTermStats;
use App\Services\Seo\Alternates;
use Carbon\Carbon;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Sitemaps: an index, plus one file per market.
 *
 * ## Four kinds of page, and only those (2026-11-07)
 *
 * The owner's decision: the Daily Coves, the gift personas (with the occasion
 * Coves, the same page at its own address), the guides, and the latest
 * searches. Brand pages, products, shop Coves, gift landing pages, community
 * Coves, the board's questions and every fixed page (home, hubs, help, legal)
 * left the file that day. Like the products before them, they stay indexable
 * and linked; they are only not submitted.
 *
 * ## Product pages are deliberately not listed (2026-09-18)
 *
 * The owner's decision.
 *
 * A product page is **still indexable and still crawlable**: nothing here is
 * `noindex`, the internal links are followed, and a crawler that arrives from a
 * brand page or a Cove is welcome to it. It is simply not submitted. What that
 * changes: the catalogue was 96 of every 100 URLs in the file, it turns over
 * daily as offers come and go, and a submitted URL that reads "currently
 * unavailable" a week later is what teaches a crawler that the sitemap is not
 * worth re-reading.
 *
 * What went with it: the 5,000-URL chunking, which existed only because the
 * catalogue passed a single file's 50,000-URL limit in one market. The
 * editorial surfaces of one market are a few thousand URLs, so one file holds
 * them with room to spare, and the index names `1.xml` and nothing else.
 */
class SitemapController extends Controller
{
    public function index(): Response
    {
        $xml = Cache::remember('bc:sitemap:index', 3600, function (): string {
            /*
             * One file per market, and no count to make first.
             *
             * The number of files used to follow the product count, which is
             * why this ran a `count()` per market on a cold cache. With the
             * catalogue out of the sitemap there is exactly one file each.
             *
             * Published markets only: advertising a sitemap for a market that
             * is not open spends crawl budget to prove there is nothing there.
             */
            $entries = array_map(
                fn (Market $market): string => url("/sitemap/{$market->value}/1.xml"),
                Market::published(),
            );

            $body = implode('', array_map(
                fn (string $loc) => '<sitemap><loc>'.e($loc).'</loc></sitemap>',
                $entries,
            ));

            return '<?xml version="1.0" encoding="UTF-8"?>'
                .'<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'.$body.'</sitemapindex>';
        });

        return response($xml, 200, ['Content-Type' => 'application/xml']);
    }

    public function market(string $market, int $page): Response
    {
        $resolved = Market::tryFrom($market);
        abort_if($resolved === null, 404);

        $xml = Cache::remember("bc:sitemap:{$market}:{$page}", 3600, function () use ($resolved, $page): string {
            $alternates = app(Alternates::class);

            $urls = [];

            /*
             * Everything lives in the first file, and there is no second one.
             *
             * This gate was how a repeated block was kept out of every product
             * chunk — listing each editorial URL eight times reads as a sitemap
             * a crawler cannot trust. The chunks went with the products on
             * 2026-09-18 and the gate stayed: the index names `1.xml` only, and
             * a stale crawler asking for `2.xml` gets a valid empty file rather
             * than a 404 for something it was told about last week.
             */
            if ($page === 1) {
                /*
                 * Four kinds of page and nothing else (owner, 2026-11-07): the
                 * Daily Coves, the gift personas, the guides and the latest
                 * searches. The home page, the hubs, brand pages, shop Coves,
                 * gift landing pages, community Coves, the board and the
                 * help and legal pages all left the file that day. They stay
                 * indexable and linked; they are simply not submitted.
                 *
                 * The home page came back the next day (owner, 2026-11-08):
                 * Search Console reported giftcoves.com itself as not indexed
                 * (it is a redirect to a market, correctly), and a market's
                 * home was then reachable only through hreflang, with no
                 * crawlable link from its twins (the switcher is buttons) and
                 * no sitemap entry. One URL per market file, so it is cheap.
                 */
                $urls[] = [
                    'loc' => url("/{$resolved->value}"),
                    'priority' => '1.0',
                    'changefreq' => 'daily',
                ];

                /*
                 * The help pages, back the same day (owner, 2026-11-08: "keep
                 * help in"): how it works, the search tips, and the lists help
                 * with one page per topic. Legal pages stay out. Monthly: they
                 * change when a feature does.
                 */
                foreach (['help', 'search-help', 'lists-help', ...array_map(fn (string $topic) => "lists-help/{$topic}", ListHelpController::TOPICS)] as $path) {
                    $urls[] = [
                        'loc' => url("/{$resolved->value}/{$path}"),
                        'priority' => '0.4',
                        'changefreq' => 'monthly',
                    ];
                }

                // Every past Daily edition, newest first. A past edition never
                // changes, and saying so stops a crawler re-fetching ninety
                // static pages a day.
                DB::table('daily_pick_sets')
                    ->where('market', $resolved->value)
                    // Dated editions only: a persona's drop_date is null and would
                    // emit /{market}/tips/ with an empty segment.
                    ->where('kind', CoveKind::Daily->value)
                    ->where('status', PublishStatus::Published->value)
                    ->orderByDesc('drop_date')
                    ->limit(400)
                    ->pluck('slug')
                    ->each(function ($slug) use (&$urls, $resolved): void {
                        $urls[] = [
                            'loc' => url($resolved->covePath($slug)),
                            'priority' => '0.6',
                            'changefreq' => 'never',
                        ];
                    });

                /*
                 * Gift personas, and occasions with them: an occasion is the
                 * same page as a persona at its own address
                 * (docs/features/occasion-coves.md). Undated and rebuilt when
                 * their products move, so weekly.
                 */
                DB::table('daily_pick_sets')
                    ->where('market', $resolved->value)
                    ->whereIn('kind', [CoveKind::Persona->value, CoveKind::Occasion->value])
                    ->where('status', PublishStatus::Published->value)
                    ->whereNotNull('slug')
                    ->orderBy('kind')
                    ->orderBy('slug')
                    ->limit(400)
                    ->get(['kind', 'slug', 'updated_at'])
                    ->each(function ($row) use (&$urls, $resolved): void {
                        $urls[] = [
                            'loc' => url("/{$resolved->value}/".CoveKind::from($row->kind)->path($row->slug, $resolved)),
                            'lastmod' => $row->updated_at ? Carbon::parse($row->updated_at)->toAtomString() : null,
                            'priority' => '0.7',
                            'changefreq' => 'weekly',
                        ];
                    });

                // The guides: every published article in the /guides space.
                DB::table('daily_pick_sets')
                    ->where('market', $resolved->value)
                    ->whereIn('kind', [CoveKind::Guide->value, CoveKind::Seasonal->value, CoveKind::Advice->value])
                    ->where('status', PublishStatus::Published->value)
                    ->orderBy('id')
                    ->get(['slug', 'updated_at'])
                    ->each(function ($guide) use (&$urls, $resolved): void {
                        $urls[] = [
                            'loc' => url("/{$resolved->value}/guides/{$guide->slug}"),
                            'lastmod' => $guide->updated_at ? Carbon::parse($guide->updated_at)->toAtomString() : null,
                            'priority' => '0.8',
                            'changefreq' => 'weekly',
                        ];
                    });

                /*
                 * The latest searches: the "latest" column of the public
                 * popular-searches page (SearchTermStats::for()), at their
                 * canonical /{market}/zoek/{term} address. Same rules as that
                 * page: searched in at least two different hours (a privacy
                 * floor, never one visitor's words), found products, and passed
                 * the length and stop-term rules. Twenty terms, so the file
                 * never submits search results wholesale, which Google warns
                 * against. Daily, because the list turns over.
                 */
                collect(app(SearchTermStats::class)->for($resolved)['latest'])
                    ->pluck('url')
                    ->filter()
                    ->unique()
                    ->each(function (string $path) use (&$urls): void {
                        $urls[] = [
                            'loc' => url($path),
                            'priority' => '0.5',
                            'changefreq' => 'daily',
                        ];
                    });
            }

            /*
             * Alternates, resolved per kind rather than per URL.
             *
             * On a cold cache this used to cost a query or two for each of the
             * five hundred editorial URLs. The product block that stood here
             * until 2026-09-18 had the same problem far worse — two queries per
             * URL, ten thousand for one file, fifty seconds to build and a 500
             * for every crawler, because the proxy gives up at thirty — and it
             * is `Alternates::forProducts()` that still carries the note. That
             * method is not dead: the product page itself uses it.
             */
            $batched = $alternates->forPaths(
                array_values(array_map(
                    fn (array $url) => parse_url($url['loc'], PHP_URL_PATH) ?: '/',
                    array_filter($urls, fn (array $url) => ! isset($url['alternates'])),
                )),
                $resolved,
            );

            $body = implode('', array_map(function (array $url) use ($batched): string {
                $xml = '<url><loc>'.e($url['loc']).'</loc>';
                if (! empty($url['lastmod'])) {
                    $xml .= '<lastmod>'.$url['lastmod'].'</lastmod>';
                }
                $xml .= '<changefreq>'.$url['changefreq'].'</changefreq>';
                $xml .= '<priority>'.$url['priority'].'</priority>';

                /*
                 * hreflang inside the sitemap as well as in the page head:
                 * Google treats the two as independent signals, and the sitemap
                 * version is what gets picked up fastest on a new URL.
                 *
                 * Resolved through the same service as the head, so the two can
                 * never disagree. They used to be computed separately, and a
                 * product's alternates were four links to 404s in both places.
                 */
                $links = $url['alternates']
                    ?? $batched[parse_url($url['loc'], PHP_URL_PATH) ?: '/']
                    ?? [];

                foreach ($links as $hrefLang => $href) {
                    $xml .= '<xhtml:link rel="alternate" hreflang="'.$hrefLang.'" href="'.e($href).'"/>';
                }

                return $xml.'</url>';
            }, $urls));

            return '<?xml version="1.0" encoding="UTF-8"?>'
                .'<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" '
                .'xmlns:xhtml="http://www.w3.org/1999/xhtml">'.$body.'</urlset>';
        });

        return response($xml, 200, ['Content-Type' => 'application/xml']);
    }

    public function robots(): Response
    {
        $allow = (bool) config('giftcoves.robots_allow');

        /*
         * An unpublished market still routes, so it still needs keeping out of
         * the index. It is absent from the sitemap and from every hreflang set,
         * which means nothing links to it — but a URL guessed or remembered
         * from elsewhere would still be crawled, and an empty market is exactly
         * the page we do not want representing the site.
         */
        $unpublished = array_map(
            fn (Market $market): string => 'Disallow: /'.$market->value.'/',
            array_values(array_filter(
                Market::cases(),
                fn (Market $market): bool => ! $market->isPublished(),
            )),
        );

        $lines = $allow
            ? [
                'User-agent: *',
                'Allow: /',
                ...$unpublished,
                // The click-out redirector must never be crawled: it is an
                // outbound affiliate hop, and crawling it burns budget on
                // redirects while looking like link-selling to a search engine.
                'Disallow: /*/go/',
                /*
                 * Filtered, sorted and paginated variants used to be blocked
                 * here as well (`?sort=`, `brand[`, `merchant[`, `page=`).
                 * Removed 2026-09-12: the owner asked for every page and every
                 * internal link to be indexable, and a link a crawler may not
                 * follow is not. The head carries no noindex on them any more
                 * either; the canonical consolidates the filtered ones.
                 */
                /*
                 * Capability URLs. Each carries a token that *is* the access,
                 * so a crawler that finds one — a forum post, a chat preview —
                 * would list a family's gift list, a person's taste profile or
                 * a Secret Santa draw under a real name. The pages say
                 * `noindex` too; this stops the fetch before it happens.
                 */
                'Disallow: /*/l/',
                'Disallow: /*/for/',
                'Disallow: /*/q/',
                'Disallow: /*/santa/',
                // `/*/invitations/` went with list-invitation redemption on
                // 2026-09-14: there is no such route left to protect.
                'Disallow: /admin',
                '',
                'Sitemap: '.url('/sitemap.xml'),
            ]
            // Staging: a full duplicate of the site would compete with the real
            // one, so nothing is crawlable at all.
            : ['User-agent: *', 'Disallow: /'];

        return response(implode("\n", $lines)."\n", 200, ['Content-Type' => 'text/plain']);
    }
}
