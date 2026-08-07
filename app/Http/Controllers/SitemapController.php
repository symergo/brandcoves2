<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\Market;
use App\Models\ProductGroup;
use App\Support\CurrentMarket;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;

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
            ];

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

            $body = implode('', array_map(function (array $url): string {
                $xml = '<url><loc>'.e($url['loc']).'</loc>';
                if (isset($url['lastmod'])) {
                    $xml .= '<lastmod>'.$url['lastmod'].'</lastmod>';
                }
                $xml .= '<changefreq>'.$url['changefreq'].'</changefreq>';
                $xml .= '<priority>'.$url['priority'].'</priority>';

                // hreflang inside the sitemap as well as in the page head:
                // Google treats the two as independent signals and the sitemap
                // version is what gets picked up fastest on a new URL.
                foreach (Market::cases() as $alternate) {
                    $path = CurrentMarket::swapMarketInPath(parse_url($url['loc'], PHP_URL_PATH) ?? '/', $alternate);
                    $xml .= '<xhtml:link rel="alternate" hreflang="'.$alternate->hrefLang().'" href="'.e(url($path)).'"/>';
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
