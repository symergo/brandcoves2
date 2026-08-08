<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Every published v1 URL must 301 to a 200.
 *
 * Not a 404, and not a chain. v1 has years of indexed URLs and inbound links,
 * and throwing them away at cutover is the single most expensive mistake
 * available during a replatform — expensive precisely because it is invisible
 * until the traffic has already gone.
 */
class LegacyRedirectTest extends TestCase
{
    use RefreshDatabase;

    /** @return iterable<string, array{string, string}> */
    public static function legacyUrls(): iterable
    {
        // Bare paths — v1's English, which had no language directory.
        yield 'root search' => ['/search', '/en/search'];
        yield 'wishlist' => ['/wishlist', '/en/lists'];
        yield 'gift whisperer' => ['/gift-whisperer', '/en/gift'];
        yield 'magazine index' => ['/magazine', '/en/guides'];
        yield 'articles index' => ['/articles', '/en/guides'];
        yield 'login' => ['/login', '/en/login'];

        // WPML language directories.
        yield 'dutch search' => ['/nl/search', '/nl-nl/search'];
        yield 'french gift' => ['/fr/gift-whisperer', '/be-fr/gift'];
        yield 'dutch wishlist' => ['/nl/wishlist', '/nl-nl/lists'];
    }

    /**
     * v1 paths with no honest v2 equivalent.
     *
     * @return iterable<string, array{string}>
     */
    public static function unmappedUrls(): iterable
    {
        yield 'an article' => ['/articles/beste-koptelefoons-2025'];
        yield 'a cove' => ['/cove/winter-finds'];
        yield 'a brand page' => ['/brands/sony'];
        yield 'a public list' => ['/list/marie/verjaardag'];
        yield 'secret santa' => ['/secret-santa/kantoor-2025'];
        yield 'a profile' => ['/profile/marie'];
        yield 'a wordpress page' => ['/over-ons'];
    }

    #[Test]
    #[DataProvider('legacyUrls')]
    public function a_v1_url_lands_on_a_working_page(string $from, string $expected): void
    {
        $response = $this->get($from);

        // 301, not 302: the move is permanent, and a 302 tells a crawler to
        // keep the old URL indexed.
        $response->assertStatus(301);
        $response->assertRedirect(url($expected));

        /*
         * And the destination has to actually work. A redirect to a 404 is
         * worse than a 404, because it looks handled — nothing in a crawl
         * report flags it, and the traffic is gone either way.
         */
        $this->get($expected)->assertSuccessful();
    }

    #[Test]
    #[DataProvider('unmappedUrls')]
    public function a_v1_url_with_no_real_equivalent_404s_rather_than_pretending(string $from): void
    {
        /*
         * Deliberately not mapped.
         *
         * `/articles/beste-koptelefoons-2025` is a specific article that does
         * not exist in v2. Redirecting it to the guides index is a soft 404 — a
         * 200 that does not contain what was asked for — and it misleads the
         * person as well as the crawler. An honest 404 is the better answer,
         * and it is the one a crawl report will actually surface.
         */
        $this->get($from)->assertNotFound();
    }

    #[Test]
    public function a_v2_url_that_does_not_exist_still_404s(): void
    {
        /*
         * The mapper must not answer for everything.
         *
         * A catch-all would swallow genuine v2 typos into a silent redirect —
         * so a broken internal link would look fine in every crawl and never
         * get fixed.
         */
        $this->get('/be-nl/p/999999/nothing')->assertNotFound();
        $this->get('/be-nl/discover/telepathy')->assertNotFound();
    }

    #[Test]
    public function an_unknown_market_prefix_is_not_treated_as_a_legacy_url(): void
    {
        // `/de/search` looks like a WPML prefix but German was never a v1
        // language. Redirecting it would invent a mapping we cannot support.
        $this->get('/de/search')->assertNotFound();
    }

    #[Test]
    public function a_live_v2_market_prefix_is_never_claimed_as_a_v1_language(): void
    {
        /*
         * `/es` is both a v1 WPML directory and a live v2 market.
         *
         * It is left out of the language map entirely: guessing which one a
         * request means would break real v2 URLs to rescue hypothetical v1
         * ones, and there are far more of the former.
         */
        $this->get('/es')->assertOk();
        $this->get('/es/search')->assertOk();
    }

    #[Test]
    public function the_language_directory_picks_the_market_that_inherited_the_traffic(): void
    {
        // v1's `/nl/` was one Dutch site. v2 has two Dutch markets, and they
        // are different catalogues — nl-nl is the larger, so it inherits.
        // Someone in Belgium reaches be-nl through the switcher.
        $this->get('/nl/search')->assertRedirect(url('/nl-nl/search'));
        $this->get('/fr/search')->assertRedirect(url('/be-fr/search'));
    }
}
