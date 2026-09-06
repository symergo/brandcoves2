<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\CoveKind;
use App\Enums\Market;
use App\Enums\PublishStatus;
use App\Models\BrandStat;
use App\Models\CommunityQuestion;
use App\Models\ProductGroup;
use App\Services\Discover\ModeRegistry;
use App\Services\Seo\Alternates;
use Carbon\Carbon;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Sitemaps.
 *
 * Split into an index plus per-market files: a single sitemap is capped at
 * 50,000 URLs and the catalogue will pass that in one market alone.
 *
 * Only products worth landing on are listed — in stock, priced, with an image.
 * Submitting URLs that render as "currently unavailable" wastes crawl budget
 * and teaches the crawler that the sitemap is unreliable.
 */
class SitemapController extends Controller
{
    /**
     * URLs per sitemap file.
     *
     * The protocol allows 50,000 and 50 MB uncompressed, so 20,000 looked
     * comfortable. It was not: every entry carries five `<xhtml:link>`
     * alternates, one per market, which makes a URL roughly 550 bytes rather
     * than the ~90 a bare `<loc>` costs. 20,000 of those is **11 MB**, and
     * building that string, holding the Eloquent collection behind it and
     * writing the result to Redis all inside one request exceeded the web
     * process's memory limit — a 500 on a file that generated perfectly from the
     * CLI, where memory_limit is different. The 500 is what a crawler sees.
     *
     * 5,000 keeps a file near 3 MB and costs four times as many files, which is
     * free: the index lists them and a crawler fetches them independently.
     */
    private const CHUNK = 5_000;

    public function index(): Response
    {
        $xml = Cache::remember('bc:sitemap:index', 3600, function (): string {
            $entries = [];

            // Published only. Advertising a market sitemap that resolves to an
            // empty catalogue spends crawl budget to prove there is nothing
            // there.
            foreach (Market::published() as $market) {
                $count = ProductGroup::query()
                    ->forMarket($market)
                    ->presentable()
                    ->count();

                $pages = max(1, (int) ceil($count / self::CHUNK));
                for ($page = 1; $page <= $pages; $page++) {
                    $entries[] = url("/sitemap/{$market->value}/{$page}.xml");
                }
            }

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
             * Everything that is not a product goes in the first chunk only.
             *
             * The brand block was gated this way from the start, with the
             * reason written beside it: repeating a block in every chunk lists
             * each URL dozens of times, which a crawler reads as a sitemap it
             * cannot trust. The statics, the discovery modes, the guides, the
             * Shop Coves, the personas and four hundred dailies were not gated,
             * so a market with eight product chunks listed its five hundred
             * editorial URLs eight times — and rebuilt them, with their
             * alternates, eight times over.
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

                // One landing per discovery mode. Each is a distinct answer to a
                // distinct question, which is exactly what makes them worth
                // indexing separately rather than as query strings on one page.
                foreach (array_keys(app(ModeRegistry::class)->all()) as $mode) {
                    $urls[] = [
                        'loc' => url("/{$resolved->value}/discover/{$mode}"),
                        'priority' => '0.6',
                        'changefreq' => 'weekly',
                    ];
                }

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

            $groups = ProductGroup::query()
                ->forMarket($resolved)
                ->presentable()
                ->orderBy('id')
                ->forPage($page, self::CHUNK)
                ->get(['id', 'slug', 'updated_at', 'merchant_count', 'identity_key']);

            /*
             * Product alternates, batched.
             *
             * Resolved per URL this cost two queries each — ten thousand for one
             * file, fifty seconds to build, and a 500 for every crawler because
             * the proxy gives up at thirty. Precomputed here they are one query
             * and the alternates travel with the URL.
             */
            $productAlternates = $alternates->forProducts(
                $groups->pluck('identity_key', 'id')->all(),
            );

            $groups->each(function (ProductGroup $group) use (&$urls, $resolved, $productAlternates): void {
                $urls[] = [
                    'loc' => url("/{$resolved->value}/p/{$group->id}/{$group->slug}"),
                    'lastmod' => $group->updated_at?->toAtomString(),
                    'alternates' => $productAlternates[$group->id] ?? [],
                    // A product several shops carry is a better landing page
                    // than one with a single offer — that is the comparison
                    // this site exists to show.
                    'priority' => $group->merchant_count > 1 ? '0.8' : '0.6',
                    'changefreq' => 'daily',
                ];
            });

            $body = implode('', array_map(function (array $url) use ($alternates, $resolved): string {
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
                    ?? $alternates->for(parse_url($url['loc'], PHP_URL_PATH) ?? '/', $resolved);

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
                // Filtered and sorted variants are noindexed in the head too;
                // this stops the crawl before it starts.
                'Disallow: /*?*sort=',
                /*
                 * Array parameters. The URL the site generates is
                 * `brand%5B0%5D=Sony` — a browser shows the brackets encoded —
                 * so the rule used to read `brand=` and matched nothing. Both
                 * spellings, because a hand-typed link may carry the bare
                 * bracket and a crawler matches the URL as written.
                 */
                'Disallow: /*?*brand%5B',
                'Disallow: /*?*brand[',
                'Disallow: /*?*merchant%5B',
                'Disallow: /*?*merchant[',
                'Disallow: /*?*page=',
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
                'Disallow: /*/invitations/',
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
