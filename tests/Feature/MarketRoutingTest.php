<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\Market;
use App\Support\MarketPreference;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MarketRoutingTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function every_market_serves(): void
    {
        foreach (Market::cases() as $market) {
            $this->get("/{$market->value}")->assertOk();
        }
    }

    #[Test]
    public function an_unknown_market_is_not_found(): void
    {
        // The route pattern constrains {market}, so this must 404 at the router
        // rather than reaching a controller with a bad value.
        $this->get('/nope')->assertNotFound();
        $this->get('/de-de')->assertNotFound();
    }

    #[Test]
    public function the_root_redirects_to_the_negotiated_market(): void
    {
        $this->withHeader('Accept-Language', 'fr-BE,fr;q=0.9')
            ->get('/')
            ->assertRedirect('/be-fr');

        $this->withHeader('Accept-Language', 'nl-NL,nl;q=0.9')
            ->get('/')
            ->assertRedirect('/nl-nl');
    }

    #[Test]
    public function the_root_redirect_is_temporary(): void
    {
        // 302, never 301: the guess comes from a request header and must not be
        // cached into permanence, pinning a visitor to a market they never chose.
        $this->get('/')->assertStatus(302);
    }

    /*
     * The remembered choice.
     *
     * Accept-Language is a good first guess and a bad permanent answer: a
     * Belgian machine whose browser language is plain "Nederlands" reports
     * nl-NL, so it lands on the Dutch catalogue, and before the cookie existed
     * nothing remembered the correction.
     */

    #[Test]
    public function choosing_a_market_records_it_and_goes_there(): void
    {
        $this->post('/market', ['market' => 'be-nl'])
            ->assertRedirect('/be-nl')
            ->assertCookie(MarketPreference::COOKIE, 'be-nl');
    }

    #[Test]
    public function a_chosen_market_beats_the_browser_language(): void
    {
        // The whole point. The header still says the Netherlands and the
        // visitor still gets Belgium, because they said so.
        $this->withCookie(MarketPreference::COOKIE, 'be-nl')
            ->withHeader('Accept-Language', 'nl-NL,nl;q=0.9')
            ->get('/')
            ->assertRedirect('/be-nl');
    }

    #[Test]
    public function merely_visiting_a_market_does_not_record_it(): void
    {
        // A shared link must not repoint someone's home market. Only the
        // switcher writes the cookie; SetMarket deliberately does not, or
        // opening a friend's /nl-nl/... link would silently move you.
        $this->get('/nl-nl')->assertCookieMissing(MarketPreference::COOKIE);
    }

    #[Test]
    public function an_unpublished_market_cannot_be_chosen(): void
    {
        // The switcher never offers `es`, so a request naming it did not come
        // from the switcher. Rejected server-side rather than trusted.
        $this->post('/market', ['market' => 'es'])->assertSessionHasErrors('market');
        $this->post('/market', ['market' => 'de-de'])->assertSessionHasErrors('market');
    }

    #[Test]
    public function a_choice_that_has_since_been_unpublished_is_ignored(): void
    {
        // The cookie outlives deploys by a year, so it can name a market that
        // has been withdrawn. Honouring it would pin the visitor to a catalogue
        // with no supply — fall through to negotiation instead.
        $this->withCookie(MarketPreference::COOKIE, 'es')
            ->withHeader('Accept-Language', 'fr-BE,fr;q=0.9')
            ->get('/')
            ->assertRedirect('/be-fr');
    }

    #[Test]
    public function the_root_redirect_is_never_shared_between_visitors(): void
    {
        // It varies on a cookie and on a request header. A CDN holding one copy
        // would hand the next visitor somebody else's market.
        //
        // Asserted per directive rather than as one string: Symfony's
        // ResponseHeaderBag re-serialises Cache-Control from a parsed map, so
        // the order is its business and not something to pin a test to.
        $cacheControl = (string) $this->get('/')->headers->get('Cache-Control');

        $this->assertStringContainsString('no-store', $cacheControl);
        $this->assertStringContainsString('private', $cacheControl);
    }

    #[Test]
    public function an_unknown_language_falls_back_to_the_default_market(): void
    {
        $this->withHeader('Accept-Language', 'ja-JP,ja;q=0.9')
            ->get('/')
            ->assertRedirect('/'.Market::default()->value);
    }

    #[Test]
    public function the_response_declares_its_language(): void
    {
        // Caches and CDNs must not serve a Dutch page to a French visitor.
        $this->get('/be-fr')->assertHeader('Content-Language', 'fr-BE');
        $this->get('/nl-nl')->assertHeader('Content-Language', 'nl-NL');
    }

    #[Test]
    public function the_document_language_follows_the_market_not_the_app_locale(): void
    {
        // be-nl and nl-nl are the same language and different markets; search
        // engines need the distinction.
        $this->get('/be-nl')->assertSee('<html lang="nl-BE">', false);
        $this->get('/nl-nl')->assertSee('<html lang="nl-NL">', false);
    }

    /*
     * An unpublished market: routable, never advertised.
     *
     * `es` has no supply — Awin reports no advertiser coverage for Spain and bol
     * does not operate there — so it would be an empty shop in the switcher and
     * a fifth market sitemap leading nowhere. Hidden rather than deleted, so the
     * copy bank and Cove plans can be built before it opens.
     */

    #[Test]
    public function an_unpublished_market_still_routes(): void
    {
        // Deliberate: hiding it must not break the URLs, or preparing content
        // for it becomes impossible and reopening it becomes a migration.
        $this->assertFalse(Market::Es->isPublished());
        $this->get('/es')->assertOk();
    }

    #[Test]
    public function an_unpublished_market_is_not_offered_in_the_switcher(): void
    {
        /*
         * Read the props rather than the HTML: the payload is JSON-encoded, so
         * "España" and "Français" arrive as \u escapes and a string match on
         * the document would pass for the wrong reason.
         *
         * The payload is grouped by country since the switcher was split into a
         * flag and a language (see localisation.md), so the markets are one
         * level down — every language entry under every flag.
         */
        $markets = collect($this->get('/be-nl')->viewData('page')['props']['markets'])
            ->flatMap(fn (array $country): array => array_column($country['languages'], 'market'))
            ->all();

        $this->assertNotContains('es', $markets);
        $this->assertContains('be-fr', $markets);

        // Same set, and each exactly once — a market offered under two flags
        // would be a market whose catalogue depends on how you got to it.
        $expected = Market::published();
        sort($expected);
        $offered = array_map(fn (string $key): Market => Market::from($key), $markets);
        sort($offered);

        $this->assertSame($expected, $offered);
    }

    #[Test]
    public function an_unpublished_market_is_never_negotiated(): void
    {
        // Sending a Spanish speaker to an empty catalogue is worse than sending
        // them to the default, which at least has products.
        $this->withHeader('Accept-Language', 'es-ES,es;q=0.9')
            ->get('/')
            ->assertRedirect('/'.Market::default()->value);

        $this->assertSame(Market::default(), Market::fromAcceptLanguage('es-ES,es;q=0.9'));
    }

    #[Test]
    public function an_unpublished_market_is_absent_from_hreflang(): void
    {
        // Declaring it tells a crawler there is a Spanish equivalent worth
        // indexing, which is the opposite of hiding it.
        $response = $this->get('/be-nl');

        $response->assertDontSee('hreflang="es-ES"', false);
        $response->assertSee('hreflang="nl-BE"', false);
    }

    #[Test]
    public function an_unpublished_market_is_absent_from_the_sitemap_index(): void
    {
        $this->get('/sitemap.xml')
            ->assertOk()
            ->assertDontSee('/sitemap/es/', false)
            ->assertSee('/sitemap/be-nl/', false);
    }

    #[Test]
    public function an_unpublished_market_is_disallowed_in_robots(): void
    {
        // It still routes and nothing links to it, but a URL remembered from
        // elsewhere would still be crawled.
        config(['giftcoves.robots_allow' => true]);

        $this->get('/robots.txt')
            ->assertOk()
            ->assertSee('Disallow: /es/', false);
    }

    #[Test]
    public function staging_is_kept_out_of_the_index(): void
    {
        config(['giftcoves.robots_allow' => false]);
        $this->get('/be-nl')->assertSee('noindex', false);

        config(['giftcoves.robots_allow' => true]);
        $this->get('/be-nl')->assertDontSee('noindex', false);
    }

    #[Test]
    public function a_visitor_gets_a_durable_anonymous_identity(): void
    {
        // The gift wizard and wishlist tray must work before signup.
        $this->get('/be-nl')->assertCookie('bc_visitor');

        $this->assertDatabaseCount('anonymous_identities', 1);
    }
}
