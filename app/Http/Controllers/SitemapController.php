<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\CoveKind;
use App\Enums\Market;
use App\Enums\PublishStatus;
use App\Models\BrandStat;
use App\Models\CommunityQuestion;
use App\Services\Seo\Alternates;
use Carbon\Carbon;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Sitemaps: an index, plus one file per market.
 *
 * ## Product pages are deliberately not listed (2026-09-18)
 *
 * The owner's decision. Everything a person is meant to land on from a search
 * engine is editorial — the home page, the Coves, the guides, the brand pages,
 * the personas, the answered questions — and those are what this submits.
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
                $urls = [
                    ['loc' => url("/{$resolved->value}"), 'priority' => '1.0', 'changefreq' => 'daily'],
                    ['loc' => url("/{$resolved->value}/search"), 'priority' => '0.5', 'changefreq' => 'weekly'],

                    /*
                     * How the box and the camera work. "How do I scan a barcode to
                     * compare prices" is a real query with real intent, and the
                     * search page itself cannot answer it — it is a results page
                     * with nothing on it until somebody types.
                     */
                    ['loc' => url("/{$resolved->value}/search-help"), 'priority' => '0.4', 'changefreq' => 'monthly'],

                    /*
                     * Help: the how-to pages gathered, with the report form under
                     * them. Listed for the reason the feedback form was listed
                     * before it took this address - a page in the menu that no
                     * crawler is told about is the shape of a page somebody forgot
                     * rather than one deliberately kept private. `/feedback` is a
                     * 301 to here now and is deliberately not listed: a sitemap
                     * naming a redirect asks a crawler to discover the same page
                     * twice.
                     */
                    ['loc' => url("/{$resolved->value}/help"), 'priority' => '0.4', 'changefreq' => 'monthly'],

                    ['loc' => url($resolved->covePath()), 'priority' => '0.9', 'changefreq' => 'daily'],
                    ['loc' => url("/{$resolved->value}/gift-ideas"), 'priority' => '0.8', 'changefreq' => 'weekly'],
                    ['loc' => url("/{$resolved->value}/guides"), 'priority' => '0.7', 'changefreq' => 'weekly'],

                    /*
                     * The overview across all three. Lower priority than any of the
                     * indexes it links to — it holds no text of its own, and a
                     * crawler that finds the archives through it has found the
                     * better page. It is listed for the internal links: it is the
                     * only node connecting the daily column, the persona shelf and
                     * the article archive to each other.
                     */
                    ['loc' => url("/{$resolved->value}/coves"), 'priority' => '0.5', 'changefreq' => 'daily'],
                    ['loc' => url("/{$resolved->value}/brands"), 'priority' => '0.6', 'changefreq' => 'weekly'],

                    /*
                     * The shop directory. Monthly, because it changes when an
                     * advertiser is onboarded and not otherwise — the page holds no
                     * catalogue data at all, which is also why it is cheap enough
                     * to be worth crawling.
                     */
                    ['loc' => url("/{$resolved->value}/shops"), 'priority' => '0.5', 'changefreq' => 'monthly'],

                    /*
                     * The board, the popular-searches hub and the list help.
                     * All three are linked from the header or the footer and
                     * were in no sitemap — the shape of a page somebody forgot,
                     * which is what the `/help` note above says of itself.
                     */
                    ['loc' => url("/{$resolved->value}/ask"), 'priority' => '0.6', 'changefreq' => 'daily'],
                    ['loc' => url("/{$resolved->value}/popular-searches"), 'priority' => '0.5', 'changefreq' => 'weekly'],
                    ['loc' => url("/{$resolved->value}/lists-help"), 'priority' => '0.4', 'changefreq' => 'monthly'],
                    // The eight topics under it, since 2026-09-08. "How do I
                    // share a wish list" is a query, and the index alone would
                    // make a search engine guess which page answers it.
                    ...array_map(fn (string $topic) => [
                        'loc' => url("/{$resolved->value}/lists-help/{$topic}"),
                        'priority' => '0.4',
                        'changefreq' => 'monthly',
                    ], ListHelpController::TOPICS),

                    /*
                     * An about page is a trust signal a search engine looks for, and
                     * a privacy policy nobody can find is a privacy policy nobody
                     * believes. Low priority, rarely changing, and listed.
                     */
                    ['loc' => url("/{$resolved->value}/about"), 'priority' => '0.4', 'changefreq' => 'yearly'],
                    ['loc' => url("/{$resolved->value}/privacy"), 'priority' => '0.3', 'changefreq' => 'yearly'],
                    ['loc' => url("/{$resolved->value}/terms"), 'priority' => '0.3', 'changefreq' => 'yearly'],
                    ['loc' => url("/{$resolved->value}/gift"), 'priority' => '0.8', 'changefreq' => 'weekly'],

                    /*
                     * The two hubs. Both are top-level nav destinations and neither
                     * was listed — `/gift-cove` had been missing since it shipped.
                     *
                     * They matter to a crawler for the reason they matter to a
                     * visitor: each is the only page that explains what a whole
                     * section is for, and each is the densest internal-link node on
                     * its half of the site. Weekly rather than daily — the tools
                     * they describe change far less often than the editorial does.
                     */
                    ['loc' => url("/{$resolved->value}/gift-cove"), 'priority' => '0.7', 'changefreq' => 'weekly'],
                    ['loc' => url("/{$resolved->value}/discover-cove"), 'priority' => '0.7', 'changefreq' => 'weekly'],
                    ['loc' => url("/{$resolved->value}/surprise"), 'priority' => '0.6', 'changefreq' => 'daily'],
                ];

                // Published guides and every past edition. The archive is the point:
                // a daily page whose history 404s has nothing accumulating.
                DB::table('daily_pick_sets')
                    ->where('market', $resolved->value)
                    // The article kinds only. A Daily is listed by date above and a
                    // persona by slug below; this block is the /guides space.
                    ->whereIn('kind', ['guide', 'seasonal', 'advice'])
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
                 * Shop Coves. Their own block because they are their own URL
                 * space: the query above is the `/guides` one and deliberately
                 * lists kinds rather than asking `isArticle()`, so a sixth kind
                 * outside that space has to say so here.
                 *
                 * Weekly like the guides. The text describes a shop rather than a
                 * price, so it changes when somebody rewrites it and not when the
                 * catalogue moves.
                 */
                DB::table('daily_pick_sets')
                    ->where('market', $resolved->value)
                    ->where('kind', CoveKind::Shop->value)
                    ->where('status', PublishStatus::Published->value)
                    ->orderBy('id')
                    ->get(['slug', 'updated_at'])
                    ->each(function ($cove) use (&$urls, $resolved): void {
                        $urls[] = [
                            'loc' => url("/{$resolved->value}/shops/{$cove->slug}"),
                            'lastmod' => $cove->updated_at ? Carbon::parse($cove->updated_at)->toAtomString() : null,
                            'priority' => '0.6',
                            'changefreq' => 'weekly',
                        ];
                    });

                /*
                 * Answered questions on the board.
                 *
                 * The one URL space here that grows from what visitors write,
                 * and it had no discovery path at all: the index shows the
                 * newest twenty and nothing links to the rest. Answered only —
                 * AskController noindexes a question nobody has answered, and a
                 * sitemap naming a noindex page asks for a crawl it then
                 * refuses.
                 */
                CommunityQuestion::query()
                    ->forMarket($resolved)
                    ->published()
                    ->where('answers_count', '>', 0)
                    ->orderByDesc('published_at')
                    ->limit(2000)
                    ->get(['id', 'title', 'updated_at'])
                    ->each(function (CommunityQuestion $question) use (&$urls, $resolved): void {
                        $urls[] = [
                            'loc' => url("/{$resolved->value}/ask/{$question->id}/{$question->slug()}"),
                            'lastmod' => $question->updated_at?->toAtomString(),
                            'priority' => '0.5',
                            'changefreq' => 'weekly',
                        ];
                    });

                /*
                 * Brand pages.
                 *
                 * The block that was gated on the first chunk from the start,
                 * for the reason now written above `if ($page === 1)`.
                 *
                 * `pageworthy` is what keeps this honest: the same three-product
                 * threshold the controller enforces. Listing a URL that 404s is worse
                 * than not listing it.
                 */
                BrandStat::query()
                    ->forMarket($resolved)
                    ->pageworthy()
                    ->orderByDesc('product_count')
                    ->limit(2000)
                    ->get(['slug', 'product_count', 'computed_at'])
                    ->each(function (BrandStat $brand) use (&$urls, $resolved): void {
                        $urls[] = [
                            'loc' => url("/{$resolved->value}/brand/{$brand->slug}"),
                            'lastmod' => $brand->computed_at?->toAtomString(),
                            // A brand carried by a lot of the catalogue is a
                            // better landing page than one with four products.
                            'priority' => $brand->product_count >= 25 ? '0.7' : '0.5',
                            'changefreq' => 'weekly',
                        ];
                    });

                /*
                 * Gift personas.
                 *
                 * Undated and evergreen, so they get a real changefreq — unlike a
                 * past edition, which never changes again. A persona is rebuilt
                 * when its products move, and that is a page worth re-crawling.
                 */
                DB::table('daily_pick_sets')
                    ->where('market', $resolved->value)
                    ->where('kind', CoveKind::Persona->value)
                    ->where('status', PublishStatus::Published->value)
                    ->whereNotNull('slug')
                    ->orderBy('slug')
                    ->limit(400)
                    ->pluck('slug')
                    ->each(function ($slug) use (&$urls, $resolved): void {
                        $urls[] = [
                            'loc' => url("/{$resolved->value}/gift-ideas/{$slug}"),
                            'priority' => '0.7',
                            'changefreq' => 'weekly',
                        ];
                    });

                DB::table('daily_pick_sets')
                    ->where('market', $resolved->value)
                    // Dated editions only: a persona's drop_date is null and would
                    // emit /{market}/daily/ with an empty segment.
                    ->where('kind', CoveKind::Daily->value)
                    ->where('status', PublishStatus::Published->value)
                    ->orderByDesc('drop_date')
                    ->limit(400)
                    ->pluck('slug')
                    ->each(function ($slug) use (&$urls, $resolved): void {
                        $urls[] = [
                            'loc' => url($resolved->covePath($slug)),
                            'priority' => '0.5',
                            // A past edition never changes. Saying so stops a
                            // crawler re-fetching ninety static pages a day.
                            'changefreq' => 'never',
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
