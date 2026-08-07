<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\Market;
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

    #[Test]
    public function staging_is_kept_out_of_the_index(): void
    {
        config(['brandcoves.robots_allow' => false]);
        $this->get('/be-nl')->assertSee('noindex', false);

        config(['brandcoves.robots_allow' => true]);
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
