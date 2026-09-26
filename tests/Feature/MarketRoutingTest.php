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
        // Every case, published or not. Hiding a market (`es` today) must not
        // break its URLs, or preparing content for it becomes impossible and
        // reopening it becomes a migration.
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
    public function a_language_change_inside_a_country_keeps_the_page(): void
    {
        /*
         * be-nl and be-fr are the same catalogue. Reading a product in Dutch
         * and wanting it in French used to cost the product: the switch always
         * landed on the market home. A page with a twin in the chosen market
         * lands on the twin; the market home is the answer for everything
         * else, exactly as before.
         */
        $this->post('/market', ['market' => 'be-fr', 'path' => '/be-nl/search'])
            ->assertRedirect(url('/be-fr/search'))
            ->assertCookie(MarketPreference::COOKIE, 'be-fr');

        // Across a border the catalogue changes, so the home it is.
        $this->post('/market', ['market' => 'nl-nl', 'path' => '/be-nl/search'])
            ->assertRedirect('/nl-nl');

        // A product with no French twin has nowhere to land but the home.
        $this->post('/market', ['market' => 'be-fr', 'path' => '/be-nl/p/999999/nothing'])
            ->assertRedirect('/be-fr');
    }

    #[Test]
    public function the_path_can_never_send_anybody_off_the_site(): void
    {
        // Resolved through Alternates, never redirected to as given: a
        // protocol-relative path has no market segment and lands on the home.
        $this->post('/market', ['market' => 'be-fr', 'path' => '//evil.example/x'])
            ->assertRedirect('/be-fr');

        $this->post('/market', ['market' => 'be-fr', 'path' => 'https://evil.example/x'])
            ->assertSessionHasErrors('path');
    }

    /*
     * The market bar (2026-09-26). It replaced a dialog that covered the page
     * on a first visit; see docs/features/market-routing.md.
     */

    private const BROWSER = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/128.0 Safari/537.36';

    #[Test]
    public function a_first_visit_gets_a_bar_and_no_dialog(): void
    {
        // The browser points at the Netherlands and the page is Dutch: a bar,
        // and closing it means "yes, that is right", so it may record it.
        $this->withHeaders(['User-Agent' => self::BROWSER, 'Accept-Language' => 'nl-NL,nl;q=0.9'])
            ->get('/nl-nl')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->missing('askMarket')
                ->where('marketBar', ['suggest' => null, 'remember' => true]));

        // Nothing a dialog would need reaches the page any more.
        $this->withHeaders(['User-Agent' => self::BROWSER])
            ->get('/nl-nl')
            ->assertDontSee('aria-modal', false)
            ->assertDontSee('market_prompt', false);
    }

    #[Test]
    public function a_first_visit_by_a_link_into_another_country_is_offered_its_own(): void
    {
        /*
         * A Belgian browser with no choice on file opens a friend's Dutch
         * list. The bar offers Belgium first, and closing it must not record
         * the Netherlands: closing a bar is not choosing a home.
         */
        $this->withHeaders(['User-Agent' => self::BROWSER, 'Accept-Language' => 'nl-BE,nl;q=0.9'])
            ->get('/nl-nl')
            ->assertInertia(fn ($page) => $page->where('marketBar', ['suggest' => 'be-nl', 'remember' => false]));
    }

    #[Test]
    public function a_shared_link_into_another_country_offers_the_way_back_and_records_nothing(): void
    {
        // A choice on file for Belgium, a page in the Netherlands.
        $response = $this->withHeaders(['User-Agent' => self::BROWSER])
            ->withCookie(MarketPreference::COOKIE, 'be-fr')
            ->get('/nl-nl');

        $response->assertInertia(fn ($page) => $page->where('marketBar', ['suggest' => 'be-fr', 'remember' => false]));

        // Opening it wrote nothing: only the switcher's POST may.
        $response->assertCookieMissing(MarketPreference::COOKIE);
    }

    #[Test]
    public function a_visitor_on_their_chosen_country_sees_no_bar(): void
    {
        $this->withHeaders(['User-Agent' => self::BROWSER])
            ->withCookie(MarketPreference::COOKIE, 'nl-nl')
            ->get('/nl-nl')
            ->assertInertia(fn ($page) => $page->where('marketBar', null));

        // Another language of the same country is the same shops: no bar.
        $this->withHeaders(['User-Agent' => self::BROWSER])
            ->withCookie(MarketPreference::COOKIE, 'be-nl')
            ->get('/be-fr')
            ->assertInertia(fn ($page) => $page->where('marketBar', null));
    }

    #[Test]
    public function a_crawler_never_sees_the_bar(): void
    {
        // It keeps no cookie, so the bar would sit in every page it indexes.
        $this->withHeaders(['User-Agent' => 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)'])
            ->get('/nl-nl')
            ->assertInertia(fn ($page) => $page->where('marketBar', null));
    }

    #[Test]
    public function closing_the_bar_records_the_market_through_the_switcher_route_without_leaving_the_page(): void
    {
        // The bar's close button asks for JSON: the cookie, and no redirect
        // that would reload the page for nothing.
        $this->postJson('/market', ['market' => 'nl-nl'])
            ->assertNoContent()
            ->assertCookie(MarketPreference::COOKIE, 'nl-nl');

        // Still validated like the switcher: an unpublished market is refused.
        $this->postJson('/market', ['market' => 'es'])
            ->assertUnprocessable()
            ->assertCookieMissing(MarketPreference::COOKIE);
    }

    #[Test]
    public function keeping_the_market_you_are_on_records_it_and_stays_on_the_page(): void
    {
        /*
         * Choosing the market you are already on, from wherever you are,
         * often a friend's shared list (the first-visit dialog's "keep this
         * one" did this until 2026-09-26): landing on the home would throw
         * the link away. Still recorded.
         */
        $this->post('/market', ['market' => 'nl-nl', 'path' => '/nl-nl/l/k7m2xq9v4p'])
            ->assertRedirect('/nl-nl/l/k7m2xq9v4p')
            ->assertCookie(MarketPreference::COOKIE, 'nl-nl');

        // Another country is a different catalogue: its home, as before.
        $this->post('/market', ['market' => 'be-nl', 'path' => '/nl-nl/l/k7m2xq9v4p'])
            ->assertRedirect('/be-nl');
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

    // Not offered in the switcher: MarketSwitcherTest::the_switcher_offers_every_published_market_once_and_nothing_else.

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
}
