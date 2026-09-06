<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\Market;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The page behind every address that does not exist.
 *
 * Three ways to arrive and they take different paths through the framework, so
 * each is asserted rather than assumed: a route that matched and gave up goes
 * through the exception handler, and a URL no route matched goes through one of
 * the two fallback routes. A regression in either leaves the framework's grey
 * box in front of visitors, which is a change nothing else would report.
 */
class NotFoundPageTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function an_unknown_page_under_a_market_is_the_custom_page(): void
    {
        $response = $this->get('/be-nl/geen-idee-wat-dit-is');

        // The status is half the feature. A helpful page served as 200 is a
        // soft 404: every dead address becomes an indexable duplicate.
        $response->assertStatus(404);
        $response->assertInertia(fn ($page) => $page->component('Errors/NotFound'));
    }

    #[Test]
    public function it_keeps_the_market_it_was_asked_in(): void
    {
        $response = $this->get('/be-fr/aucune-idee');

        $response->assertStatus(404);
        $response->assertInertia(fn ($page) => $page
            ->component('Errors/NotFound')
            ->where('market.key', Market::BeFr->value)
            // Every link offered has to be in the market the visitor is in.
            // Sending somebody who followed a dead French link to the Dutch
            // gift finder is the failure this page exists to avoid.
            ->where('urls.gift', '/be-fr/gift'));
    }

    #[Test]
    public function a_route_that_matched_and_gave_up_gets_the_same_page(): void
    {
        /*
         * The common case, and the one that prompted the page: `/guides/{slug}`
         * is a real route, so this never reaches a fallback — the controller
         * aborts and the exception handler renders instead. 61 retired guide
         * addresses land here.
         */
        $response = $this->get('/be-nl/guides/een-gids-die-niet-bestaat');

        $response->assertStatus(404);
        $response->assertInertia(fn ($page) => $page->component('Errors/NotFound'));
    }

    #[Test]
    public function a_url_with_no_market_at_all_still_gets_the_page(): void
    {
        $response = $this->get('/wp-content/uploads/2019/thing.php');

        $response->assertStatus(404);
        $response->assertInertia(fn ($page) => $page->component('Errors/NotFound'));
    }

    #[Test]
    public function the_old_site_is_still_redirected_rather_than_shown_this_page(): void
    {
        /*
         * The regression this page could most easily have caused. The v1
         * redirect map used to be consulted only from the exception handler,
         * which was the one thing an unmatched URL reached; a fallback route
         * catches those first, so the check had to travel with it. Without
         * that, every indexed v1 address would quietly start answering "not
         * found" instead of redirecting.
         */
        $response = $this->get('/magazine');

        $response->assertStatus(301);
        $response->assertRedirectContains('guides');
    }

    #[Test]
    public function it_is_never_indexed(): void
    {
        // Indexing on, or the environment stamps `noindex, nofollow` on every
        // page and the page's own `follow` is never consulted.
        config(['giftcoves.robots_allow' => true]);

        $response = $this->get('/be-nl/niets-hier');

        // `follow`, not `nofollow`: the links on it are the point, and a
        // crawler that reads them finds the pages that do exist.
        $response->assertSee('noindex, follow', escape: false);
    }
}
