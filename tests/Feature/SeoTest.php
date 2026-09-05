<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\CoveKind;
use App\Enums\Market;
use App\Enums\PublishStatus;
use App\Enums\Source;
use App\Jobs\GroupProducts;
use App\Jobs\IngestFeed;
use App\Models\DailyPickSet;
use App\Models\Feed;
use App\Models\ProductGroup;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route as RouteFacade;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * SEO is the growth model, so these are load-bearing.
 *
 * Note on SSR: these tests assert the server-rendered <head>, which Blade owns
 * and which is therefore always present. The React <body> is pre-rendered by
 * the separate SSR container — verified manually and in CI against a running
 * instance, since spinning up Node inside PHPUnit would be testing the SSR
 * server rather than our code.
 */
class SeoTest extends TestCase
{
    use RefreshDatabase;

    private function seedCatalogue(): ProductGroup
    {
        $feed = Feed::firstOrCreate(
            ['source' => Source::Awin, 'external_feed_id' => '18755', 'market' => Market::BeNl],
            [
                'label' => 'Test advertiser',
                'enabled' => true,
                'column_map' => ['url' => base_path('tests/Fixtures/awin-sample.csv')],
            ],
        );

        IngestFeed::dispatchSync($feed->id);
        GroupProducts::dispatchSync(Market::BeNl);

        return ProductGroup::query()->where('identity_key', '4006381333931')->firstOrFail();
    }

    #[Test]
    public function a_product_page_carries_a_description_and_social_card(): void
    {
        $group = $this->seedCatalogue();

        $this->get("/be-nl/p/{$group->id}/{$group->slug}")
            ->assertOk()
            ->assertSee('<meta name="description"', escape: false)
            ->assertSee('og:title', escape: false)
            ->assertSee('og:image', escape: false)
            // Scrapers do not run JS, so these must be server-rendered.
            ->assertSee('twitter:card', escape: false);
    }

    #[Test]
    public function a_product_page_emits_an_aggregate_offer(): void
    {
        $group = $this->seedCatalogue();

        $html = $this->get("/be-nl/p/{$group->id}/{$group->slug}")->assertOk()->getContent();

        // The highest-leverage SEO on the site: this is what makes a listing
        // show "€329.99 to €349.00 from 2 sellers".
        $this->assertStringContainsString('"@type":"Product"', (string) $html);
        $this->assertStringContainsString('"@type":"AggregateOffer"', (string) $html);
        $this->assertStringContainsString('"lowPrice":"329.99"', (string) $html);
        $this->assertStringContainsString('"highPrice":"349.00"', (string) $html);
        $this->assertStringContainsString('"offerCount":2', (string) $html);
        // Only a validated GTIN is claimed as a barcode.
        $this->assertStringContainsString('"gtin13":"4006381333931"', (string) $html);
    }

    #[Test]
    public function a_title_grouped_product_never_claims_a_barcode(): void
    {
        $this->seedCatalogue();
        $group = ProductGroup::query()->where('identity_kind', 'title')->firstOrFail();

        $html = (string) $this->get("/be-nl/p/{$group->id}/{$group->slug}")->getContent();

        // The fallback identity key is an internal string. Marking it up as a
        // GTIN would be a factual lie in structured data.
        $this->assertStringNotContainsString('gtin13', $html);
    }

    #[Test]
    public function meta_descriptions_are_real_copy_not_translation_keys(): void
    {
        $group = $this->seedCatalogue();

        // Shipped once with `__('search.seo_term')` instead of
        // `__('site.search.seo_term')`. Laravel returns the key unchanged when
        // it cannot resolve it, so the meta description read literally
        // "search.seo_term" in production — visible in a search listing.
        foreach ([
            "/be-nl/p/{$group->id}/{$group->slug}",
            '/be-nl/search?q=koptelefoon',
            '/be-fr/search?q=casque',
        ] as $path) {
            $html = (string) $this->get($path)->getContent();

            preg_match('/<meta name="description" content="([^"]*)"/', $html, $m);

            $this->assertNotEmpty($m[1] ?? '', "no description on {$path}");
            $this->assertDoesNotMatchRegularExpression(
                '/^(site\.)?(search|product|nav|home|footer)\./',
                $m[1],
                "unresolved translation key in the description on {$path}",
            );
        }
    }

    /**
     * The home page shipped with no `PageMeta` call at all, so the page most
     * likely to be linked from outside had no meta description and an og:title
     * of nothing — a shared link rendered as a bare card with the URL on it.
     * The Gift Cove and the Discover Cove had the same gap.
     *
     * ## The list is derived, not maintained
     *
     * It used to be six hard-coded paths, under a docblock claiming it walked
     * "every static indexable page". It did not, and on 2026-09-05 `/lists`,
     * `/santa` and `/login` were all serving `index, follow` with no title and
     * no description — none of them on the list, all of them added to the route
     * table long after it was written.
     *
     * A hand-kept list of pages cannot catch the page nobody remembered to add
     * to it, which is the only kind that ever has this bug. So the routes are
     * the list: every GET route under `{market}/` taking no other parameter.
     * Adding a page adds it here.
     *
     * Only pages that actually answer 200 and actually ask to be indexed are
     * asserted on. A redirect, an auth wall or a deliberate `noindex` is a
     * different page with a different contract — `/login` is `noindex` on
     * purpose and is meant to fall out here.
     */
    #[Test]
    public function every_indexable_static_page_carries_a_title_and_a_description(): void
    {
        /*
         * The suite runs with ROBOTS_ALLOW unset, which makes the Blade shell
         * stamp `noindex, nofollow` on everything — staging must never be
         * indexed. That would make this sweep skip every page and pass by
         * testing nothing, so indexing is switched on for the duration and the
         * robots tag then reports what each page asked for.
         */
        config(['giftcoves.robots_allow' => true]);

        $checked = 0;

        foreach ($this->staticMarketPaths() as $path) {
            $response = $this->get($path);

            // Not a page a crawler will ever index and grade us on: a redirect,
            // an auth wall, a 404.
            if ($response->getStatusCode() !== 200) {
                continue;
            }

            // JSON endpoints and generated images live under the same prefix and
            // carry no <head> to assert on. Decided by what came back rather
            // than by a list of paths to skip, so a new one needs no edit here.
            if (! str_contains((string) $response->headers->get('content-type'), 'text/html')) {
                continue;
            }

            $html = (string) $response->getContent();

            preg_match('/<meta name="robots" content="([^"]*)"/', $html, $robots);

            if (! str_contains($robots[1] ?? '', 'index') || str_contains($robots[1] ?? '', 'noindex')) {
                continue;
            }

            preg_match('/<meta name="description" content="([^"]*)"/', $html, $description);
            preg_match('/<meta property="og:title" content="([^"]*)"/', $html, $title);

            $this->assertNotEmpty(
                $description[1] ?? '',
                "no meta description on {$path}, which is indexable",
            );
            $this->assertNotEmpty(
                $title[1] ?? '',
                "no og:title on {$path}, which is indexable",
            );

            foreach ([$description[1], $title[1]] as $value) {
                /*
                 * Laravel returns a translation key unchanged when it cannot
                 * resolve one, so `site.search.seo_term` written as
                 * `search.seo_term` shipped the literal string into a meta
                 * description on production.
                 */
                $this->assertDoesNotMatchRegularExpression(
                    '/^(site\.)?[a-z_]+\.[a-z_]+$/',
                    $value,
                    "unresolved translation key on {$path}: {$value}",
                );
            }

            $checked++;
        }

        // A route table that stops producing candidates would turn this test
        // green by testing nothing at all.
        $this->assertGreaterThanOrEqual(6, $checked, 'the route sweep found almost no indexable pages');
    }

    /**
     * Every page under a market prefix that a crawler can reach without an id.
     *
     * `{market}` is substituted; anything taking a second parameter is a keyed
     * page — a product, a brand, a guide — with its own tests and its own
     * fixtures.
     *
     * @return list<string>
     */
    private function staticMarketPaths(): array
    {
        $paths = [];

        foreach (RouteFacade::getRoutes() as $route) {
            if (! in_array('GET', $route->methods(), true)) {
                continue;
            }

            $uri = $route->uri();

            if ($uri !== '{market}' && ! str_starts_with($uri, '{market}/')) {
                continue;
            }

            // One placeholder, and it is the market.
            if (substr_count($uri, '{') !== 1) {
                continue;
            }

            // Generated images: a card render each, several seconds apiece, and
            // covered by their own tests. Skipped by prefix rather than by name.
            if (str_starts_with($uri, '{market}/og/')) {
                continue;
            }

            $paths[] = '/'.rtrim(str_replace('{market}', 'be-nl', $uri), '/');
        }

        sort($paths);

        return array_values(array_unique($paths));
    }

    /**
     * SSR is dispatched to, rather than skipped because a file is missing.
     *
     * Inertia's `ensure_bundle_exists` defaults to true: before calling the SSR
     * service it checks for `bootstrap/ssr/ssr.js` on the local filesystem and
     * returns null — no log line, no exception — when it is absent. This
     * deployment splits `app` and `ssr` into separate containers, so the PHP
     * container has no bundle and never will, and that guard fired on every
     * request while the SSR service sat healthy and unused.
     *
     * Every page on production and staging shipped as `<div id="app"></div>`:
     * no `<title>`, no `<h1>`, no body copy, for every crawler, for as long as
     * the split has existed. Nothing looked wrong in a browser, because the
     * client hydrates.
     *
     * The suite itself runs with `INERTIA_SSR_ENABLED=false` (phpunit.xml), so
     * this cannot be asserted through a rendered page — the config value is the
     * thing that broke and the thing worth pinning.
     */
    #[Test]
    public function inertia_does_not_look_for_an_ssr_bundle_the_app_container_never_has(): void
    {
        $this->assertFalse(
            (bool) config('inertia.ssr.ensure_bundle_exists'),
            'ensure_bundle_exists is on again; app has no bootstrap/ssr and SSR will silently stop',
        );
    }

    #[Test]
    public function metadata_never_leaks_between_requests(): void
    {
        $group = $this->seedCatalogue();
        $titleGrouped = ProductGroup::query()->where('identity_kind', 'title')->firstOrFail();

        // Render a page that emits a Product schema with a GTIN...
        $this->get("/be-nl/p/{$group->id}/{$group->slug}")->assertOk();

        // ...then one that must not. An earlier version held this in static
        // properties, so JSON-LD accumulated and a page carried the structured
        // data of everything rendered before it. Invisible under PHP-FPM, but
        // under FrankenPHP's persistent workers it means one visitor's page can
        // advertise another product's price.
        $html = (string) $this->get("/be-nl/p/{$titleGrouped->id}/{$titleGrouped->slug}")->getContent();

        $this->assertStringNotContainsString('4006381333931', $html, 'the previous page\'s GTIN leaked');
        $this->assertSame(
            1,
            substr_count($html, '"@type":"Product"'),
            'exactly one Product schema per page',
        );
    }

    #[Test]
    public function filtered_and_paginated_searches_are_kept_out_of_the_index(): void
    {
        $this->seedCatalogue();

        // A facet UI generates a combinatorial explosion of URLs. Left
        // indexable, a crawler spends its whole budget on near-identical
        // filtered pages and never reaches the products worth ranking.
        $this->get('/be-nl/search?q=koptelefoon&brand[]=Sony')
            ->assertSee('name="robots" content="noindex, follow"', escape: false);

        $this->get('/be-nl/search?q=koptelefoon&page=2')
            ->assertSee('name="robots" content="noindex, follow"', escape: false);

        $this->get('/be-nl/search?q=koptelefoon&sort=price_asc')
            ->assertSee('name="robots" content="noindex, follow"', escape: false);
    }

    #[Test]
    public function a_filtered_search_canonicalises_to_the_bare_term(): void
    {
        $this->seedCatalogue();

        // So any ranking signal a filtered variant picks up consolidates onto
        // one URL instead of being split across dozens.
        $this->get('/be-nl/search?q=koptelefoon&brand[]=Sony&sort=price_asc')
            ->assertSee('rel="canonical" href="'.url('/be-nl/search').'?q=koptelefoon"', escape: false);
    }

    #[Test]
    public function the_canonical_is_shared_with_the_client_so_it_survives_a_client_side_visit(): void
    {
        /*
         * The head is rendered once by the Blade shell, and Inertia's <Head>
         * manages only the title — so without this prop the canonical link goes
         * on advertising whichever page was loaded from the server, for the
         * whole session, while the address bar moves.
         *
         * iOS share sheets read that link in preference to the address bar. A
         * visitor who tapped through to a product and shared it therefore sent
         * the *entry* page's URL, and WhatsApp drew that page's card: a previous
         * link and a previous card, indistinguishable from a caching problem.
         * Reported 2026-09-02.
         *
         * Asserted on the Inertia payload rather than the rendered tag, because
         * the tag is only correct on a full page load — the client-side visit
         * this protects has nothing but the prop.
         */
        $this->seedCatalogue();

        $response = $this->get('/be-nl/search?q=koptelefoon&brand[]=Sony&sort=price_asc');

        $page = $response->viewData('page');

        $this->assertSame(
            url('/be-nl/search').'?q=koptelefoon',
            $page['props']['canonical'] ?? null,
            'the client must receive the same canonical the shell would have rendered',
        );
    }

    #[Test]
    public function the_card_url_carries_the_commit_so_a_deploy_reaches_the_platforms(): void
    {
        /*
         * The endpoint keys its cache on the commit so a bad card cannot outlive
         * a deploy. That protection stopped at our own front door: the URL was
         * permanent and carries a week of max-age, so a platform that had
         * fetched a card kept showing those bytes however the endpoint had been
         * fixed. WhatsApp is the sharp case — it caches previews on the sender's
         * device, where there is nothing to purge and no request we can make.
         * Changing the URL is the only lever there is.
         *
         * The commit rather than the drawn text, deliberately: prices move
         * constantly, and a URL that moved with them would cost a fetch and a
         * render on every platform several times a day while never hitting a
         * cache. See App\Services\Seo\SocialCard.
         */
        $group = $this->seedCatalogue();

        config(['giftcoves.commit_sha' => 'abcdef0123456789']);

        // The canonical slug URL, as every other test here uses: the bare
        // /p/{id} form 301s to it, and a redirect body carries no meta tags.
        $this->get("/be-nl/p/{$group->id}/{$group->slug}")
            ->assertOk()
            ->assertSee('og/p/'.$group->id.'.png?v=abcdef012345', escape: false);
    }

    #[Test]
    public function the_card_url_is_left_bare_off_a_deployment(): void
    {
        // A laptop has no commit to name. Inventing one would put a token in the
        // markup that means nothing, and make the local page differ from the
        // deployed one for no reason anybody could act on.
        $group = $this->seedCatalogue();

        config(['giftcoves.commit_sha' => null]);

        $this->get("/be-nl/p/{$group->id}/{$group->slug}")
            ->assertOk()
            ->assertSee('og/p/'.$group->id.'.png"', escape: false);
    }

    #[Test]
    public function the_search_title_and_the_headline_are_not_the_same_string(): void
    {
        /*
         * Three names for one page, each doing a different job, and the whole
         * point is that they differ:
         *
         *   h1        the edition's editorial name — what it is called
         *   og:title  the same, because a social card is not a search result
         *   <title>   the name plus a phrase somebody would actually type
         *
         * Collapsing them is the trade people assume they have to make. They do
         * not: the tags are separate, so the writing keeps its voice and the
         * search result still carries the keyword.
         */
        $edition = DailyPickSet::create([
            'market' => Market::BeNl->value,
            'kind' => CoveKind::Daily->value,
            'drop_date' => '2026-08-08',
            'theme_title' => 'De laatste vakantiedag',
            'theme_slug' => 'vakantie',
            'theme_source' => 'theme',
            'status' => PublishStatus::Published->value,
            'published_at' => CarbonImmutable::parse('2026-08-08')->setTime(6, 0),
        ]);

        $page = $this->get('/be-nl/tips/'.$edition->slug)
            ->assertOk()
            ->viewData('page');

        $this->assertSame(
            'De laatste vakantiedag — cadeautips',
            $page['props']['edition']['seoTitle'],
            'the <title> carries the searchable phrase',
        );

        $this->assertSame(
            'De laatste vakantiedag',
            $page['props']['edition']['theme'],
            'the headline keeps the editorial name, unchanged',
        );
    }

    #[Test]
    public function robots_blocks_everything_on_staging(): void
    {
        config(['giftcoves.robots_allow' => false]);

        $body = (string) $this->get('/robots.txt')->assertOk()->getContent();

        // A full duplicate of the site in the index would compete with the real
        // one, so staging is entirely uncrawlable.
        $this->assertStringContainsString("User-agent: *\nDisallow: /", $body);
        $this->assertStringNotContainsString('Sitemap:', $body);
    }

    #[Test]
    public function robots_protects_the_click_out_redirector_in_production(): void
    {
        config(['giftcoves.robots_allow' => true]);

        $body = (string) $this->get('/robots.txt')->assertOk()->getContent();

        // Crawling an outbound affiliate hop burns budget on redirects and
        // looks like link-selling to a search engine.
        $this->assertStringContainsString('Disallow: /*/go/', $body);
        $this->assertStringContainsString('Disallow: /admin', $body);
        $this->assertStringContainsString('Sitemap: '.url('/sitemap.xml'), $body);
    }

    #[Test]
    public function the_sitemap_lists_only_products_worth_landing_on(): void
    {
        config(['giftcoves.robots_allow' => true]);
        $group = $this->seedCatalogue();

        $xml = (string) $this->get('/sitemap/be-nl/1.xml')->assertOk()->getContent();

        $this->assertStringContainsString("/be-nl/p/{$group->id}/{$group->slug}", $xml);
        // hreflang in the sitemap as well as the head: Google treats them as
        // independent signals and picks the sitemap up faster on a new URL.
        $this->assertStringContainsString('hreflang="fr-BE"', $xml);

        // A product with no image cannot be a good landing page.
        ProductGroup::query()->whereKey($group->id)->update(['image_url' => null]);
        Cache::flush();

        $xml = (string) $this->get('/sitemap/be-nl/1.xml')->getContent();
        $this->assertStringNotContainsString("/p/{$group->id}/", $xml);
    }

    #[Test]
    public function no_static_file_shadows_a_generated_route(): void
    {
        /*
         * Found in production, on the apex, minutes after cutover.
         *
         * Laravel ships a default `public/robots.txt` reading "Disallow:" —
         * allow everything. FrankenPHP serves `public/` as static files before
         * a request reaches PHP, so that file answered `/robots.txt` and
         * `SitemapController::robots()` had never once run: not on staging,
         * where it should have served a blanket noindex, and not on production,
         * where it should have kept crawlers out of `/*\/go/` and `/admin` and
         * pointed them at the sitemap.
         *
         * Every existing robots test passed throughout, because PHPUnit calls
         * the router directly and never touches the web server's static
         * handling. No feature test can catch this — the assertion has to be
         * about the filesystem.
         */
        foreach (['robots.txt', 'sitemap.xml'] as $generated) {
            $this->assertFileDoesNotExist(
                public_path($generated),
                "public/{$generated} would be served by the web server before the route that generates it",
            );
        }
    }

    #[Test]
    public function the_sitemap_index_covers_every_published_market(): void
    {
        $xml = (string) $this->get('/sitemap.xml')->assertOk()->getContent();

        foreach (Market::published() as $market) {
            $this->assertStringContainsString("/sitemap/{$market->value}/1.xml", $xml);
        }

        // An unpublished market has no supply, so its sitemap would spend crawl
        // budget proving there is nothing there.
        $hidden = array_filter(Market::cases(), fn (Market $m) => ! $m->isPublished());

        foreach ($hidden as $market) {
            $this->assertStringNotContainsString("/sitemap/{$market->value}/", $xml);
        }
    }

    #[Test]
    public function an_unstocked_product_is_noindexed_but_still_followed(): void
    {
        $group = $this->seedCatalogue();
        $group->offers()->update(['status' => 'stale']);

        // A page with no offers is thin, but its links are still worth
        // following — hence follow, not nofollow.
        $this->get("/be-nl/p/{$group->id}/{$group->slug}")
            ->assertSee('name="robots" content="noindex, follow"', escape: false);
    }
}
