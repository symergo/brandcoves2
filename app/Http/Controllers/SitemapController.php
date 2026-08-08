<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\Market;
use App\Enums\PublishStatus;
use App\Models\BrandStat;
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
    private const CHUNK = 20_000;

    public function index(): Response
    {
        $xml = Cache::remember('bc:sitemap:index', 3600, function (): string {
            $entries = [];

            foreach (Market::cases() as $market) {
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
            $urls = [
                ['loc' => url("/{$resolved->value}"), 'priority' => '1.0', 'changefreq' => 'daily'],
                ['loc' => url("/{$resolved->value}/search"), 'priority' => '0.5', 'changefreq' => 'weekly'],
                ['loc' => url("/{$resolved->value}/daily"), 'priority' => '0.9', 'changefreq' => 'daily'],
                ['loc' => url("/{$resolved->value}/guides"), 'priority' => '0.7', 'changefreq' => 'weekly'],
                ['loc' => url("/{$resolved->value}/brands"), 'priority' => '0.6', 'changefreq' => 'weekly'],
                ['loc' => url("/{$resolved->value}/gift"), 'priority' => '0.8', 'changefreq' => 'weekly'],
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
            DB::table('guides')
                ->where('market', $resolved->value)
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
             * Brand pages, only on the first page of the sitemap.
             *
             * Not paginated with the products, because the product pages run to
             * tens of thousands and brands to a few hundred — repeating the brand
             * block in every chunk would list each one dozens of times, which a
             * crawler reads as a sitemap it cannot trust.
             *
             * `pageworthy` is what keeps this honest: the same three-product
             * threshold the controller enforces. Listing a URL that 404s is worse
             * than not listing it.
             */
            if ($page === 1) {
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
            }

            DB::table('daily_pick_sets')
                ->where('market', $resolved->value)
                ->where('status', PublishStatus::Published->value)
                ->orderByDesc('drop_date')
                ->limit(400)
                ->pluck('drop_date')
                ->each(function ($date) use (&$urls, $resolved): void {
                    $urls[] = [
                        'loc' => url("/{$resolved->value}/daily/{$date}"),
                        'priority' => '0.5',
                        // A past edition never changes. Saying so stops a
                        // crawler re-fetching ninety static pages a day.
                        'changefreq' => 'never',
                    ];
                });

            ProductGroup::query()
                ->forMarket($resolved)
                ->presentable()
                ->orderBy('id')
                ->forPage($page, self::CHUNK)
                ->get(['id', 'slug', 'updated_at', 'merchant_count'])
                ->each(function (ProductGroup $group) use (&$urls, $resolved): void {
                    $urls[] = [
                        'loc' => url("/{$resolved->value}/p/{$group->id}/{$group->slug}"),
                        'lastmod' => $group->updated_at?->toAtomString(),
                        // A product several shops carry is a better landing page
                        // than one with a single offer — that is the comparison
                        // this site exists to show.
                        'priority' => $group->merchant_count > 1 ? '0.8' : '0.6',
                        'changefreq' => 'daily',
                    ];
                });

            $alternates = app(Alternates::class);

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
                foreach ($alternates->for(parse_url($url['loc'], PHP_URL_PATH) ?? '/', $resolved) as $hrefLang => $href) {
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
        $allow = (bool) config('brandcoves.robots_allow');

        $lines = $allow
            ? [
                'User-agent: *',
                'Allow: /',
                // The click-out redirector must never be crawled: it is an
                // outbound affiliate hop, and crawling it burns budget on
                // redirects while looking like link-selling to a search engine.
                'Disallow: /*/go/',
                // Filtered and sorted variants are noindexed in the head too;
                // this stops the crawl before it starts.
                'Disallow: /*?*sort=',
                'Disallow: /*?*brand=',
                'Disallow: /*?*merchant=',
                'Disallow: /*?*page=',
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
