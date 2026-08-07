<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\Market;
use App\Enums\Source;
use App\Jobs\GroupProducts;
use App\Jobs\IngestFeed;
use App\Models\Feed;
use App\Models\ProductGroup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
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
    public function robots_blocks_everything_on_staging(): void
    {
        config(['brandcoves.robots_allow' => false]);

        $body = (string) $this->get('/robots.txt')->assertOk()->getContent();

        // A full duplicate of the site in the index would compete with the real
        // one, so staging is entirely uncrawlable.
        $this->assertStringContainsString("User-agent: *\nDisallow: /", $body);
        $this->assertStringNotContainsString('Sitemap:', $body);
    }

    #[Test]
    public function robots_protects_the_click_out_redirector_in_production(): void
    {
        config(['brandcoves.robots_allow' => true]);

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
        config(['brandcoves.robots_allow' => true]);
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
    public function the_sitemap_index_covers_every_market(): void
    {
        $xml = (string) $this->get('/sitemap.xml')->assertOk()->getContent();

        foreach (Market::cases() as $market) {
            $this->assertStringContainsString("/sitemap/{$market->value}/1.xml", $xml);
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
