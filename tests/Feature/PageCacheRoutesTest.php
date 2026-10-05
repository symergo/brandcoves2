<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Middleware\CacheAnonymousPage;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Which routes the anonymous page cache may serve, walked from the route table.
 *
 * A cached page is handed to every signed-out visitor. On a list it would show
 * one visitor what another claimed (invariant 4), on an account page somebody
 * else's account, on a token page somebody else's invitation. The middleware
 * never serves a visitor with a session, but these routes must not be opted in
 * at all, and this test is what notices when one is.
 */
class PageCacheRoutesTest extends TestCase
{
    /**
     * Every route that is opted in, by name. Adding one is a decision: check
     * that its page is the same for every signed-out visitor (no Referer, no
     * session, no identity, only the query parameters CacheAnonymousPage
     * knows), then add it here.
     */
    private const OPTED_IN = [
        'home', 'search-help', 'popular-searches', 'help', 'product', 'lists-help', 'lists-help.topic',
        'gift-cove.manual', 'daily', 'daily.archive', 'daily.dated', 'daily.edition', 'gift-ideas', 'gift-ideas.landing',
        'gift-ideas.persona', 'gift-ideas.occasion', 'coves', 'community', 'community.show', 'guides', 'guides.show', 'legal',
        'shops', 'shops.show', 'brands', 'brand',
    ];

    /**
     * Never, whatever the list above says: lists and claims, accounts, people,
     * token and code pages, forms, search and the pages that differ per visit.
     */
    private const NEVER = [
        '#^/\{market\}/(lists|l|for|q|t|s|santa|ask|contribute|search|surprise|discover-cove|people|friends|notifications|gift|invites|login|auth|saved-items|list-options|list-items|recipients|scan|go)(/|$)#',
        '#\{token\}#',
    ];

    #[Test]
    public function only_the_public_pages_are_opted_in(): void
    {
        $opted = [];

        foreach (Route::getRoutes()->getRoutes() as $route) {
            if (! $this->isOptedIn($route)) {
                continue;
            }

            $opted[] = (string) $route->getName();

            $this->assertSame(['GET', 'HEAD'], $route->methods(), "{$route->uri()} caches something other than a read");

            foreach (self::NEVER as $pattern) {
                $this->assertDoesNotMatchRegularExpression($pattern, '/'.$route->uri(), "{$route->uri()} must never be cached");
            }

            foreach ($route->gatherMiddleware() as $middleware) {
                $this->assertFalse(
                    is_string($middleware) && (str_starts_with($middleware, 'auth') || str_starts_with($middleware, 'signed') || $middleware === 'guest'),
                    "{$route->uri()} is for one visitor ({$middleware}) and must not be cached",
                );
            }
        }

        sort($opted);
        $expected = self::OPTED_IN;
        sort($expected);

        $this->assertSame($expected, $opted);
    }

    #[Test]
    public function no_list_claim_or_account_route_is_opted_in(): void
    {
        foreach (Route::getRoutes()->getRoutes() as $route) {
            $uri = '/'.$route->uri();

            $private = preg_match('#^/\{market\}/(lists|l|for|q|t|s|santa|people|friends|notifications|recipients|list-items|saved-items|list-options|suggestions|alerts|search-alerts)(/|$)#', $uri) === 1
                || str_contains($uri, 'claim');

            if ($private) {
                $this->assertFalse($this->isOptedIn($route), "{$uri} carries lists, claims or an account and is opted in");
            }
        }
    }

    private function isOptedIn(RoutingRoute $route): bool
    {
        return in_array(CacheAnonymousPage::ALIAS, $route->gatherMiddleware(), true)
            || in_array(CacheAnonymousPage::class, $route->gatherMiddleware(), true);
    }
}
