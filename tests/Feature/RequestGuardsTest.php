<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\Market;
use App\Models\AnonymousIdentity;
use App\Models\Recipient;
use App\Services\Search\SearchQuery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The guards on the request path, found missing in the 2026-09-06 review.
 *
 * Each of these is a rule that looked enforced from the browser and was not:
 * a throttle that existed on the signed-in search and not the public one, an
 * identity row written for every crawler fetch, a write route outside `auth`
 * that any cookie could reach.
 */
class RequestGuardsTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function machine_routes_leave_no_visitor_behind(): void
    {
        /*
         * A crawler keeps no cookies, so every fetch of these used to insert an
         * `anonymous_identities` row — one per product page for a full crawl
         * of the sitemap — and queue a Set-Cookie that made the response
         * uncacheable.
         */
        foreach (['/robots.txt', '/sitemap.xml', '/sitemap/be-nl/1.xml', '/be-nl/og/default.png', '/health'] as $path) {
            $this->get($path)->assertOk()->assertCookieMissing('bc_visitor');
        }

        $this->assertSame(0, AnonymousIdentity::query()->count());

        // A page a person reads still gets one.
        $this->get('/be-nl')->assertOk()->assertCookie('bc_visitor');
        $this->assertSame(1, AnonymousIdentity::query()->count());
    }

    #[Test]
    public function search_is_rate_limited_like_the_signed_in_one_already_was(): void
    {
        // The limiter shares the array cache with every other test in this
        // process, so start clean and leave clean.
        Cache::flush();

        try {
            for ($i = 0; $i < 60; $i++) {
                $this->get('/be-nl/search?q=speaker')->assertOk();
            }

            $this->get('/be-nl/search?q=speaker')->assertStatus(429);
        } finally {
            Cache::flush();
        }
    }

    #[Test]
    public function creating_a_recipient_or_handing_over_a_list_needs_an_account(): void
    {
        // These sat outside `auth` with only an `Owner::exists()` check, which
        // every request passes because the identity middleware manufactures an
        // owner for anyone without a cookie.
        $this->post('/be-nl/recipients', ['name' => 'Anna'])->assertRedirect('/be-nl/login');
        $this->post('/be-nl/lists/'.Str::uuid().'/handover')->assertRedirect('/be-nl/login');

        $this->assertSame(0, Recipient::query()->count());
    }

    #[Test]
    public function a_search_query_does_not_hide_full_price_products_by_default(): void
    {
        // It defaulted to true, and every internal caller overrode it with a
        // comment saying so. The request parser still reads the box.
        $this->assertFalse((new SearchQuery(Market::BeNl))->discountedOnly);
        $this->assertFalse(SearchQuery::fromRequest(Request::create('/be-nl/search', 'GET', ['q' => 'x']), Market::BeNl)->discountedOnly);
        $this->assertTrue(SearchQuery::fromRequest(Request::create('/be-nl/search', 'GET', ['q' => 'x', 'discounted' => '1']), Market::BeNl)->discountedOnly);
    }

    #[Test]
    public function the_page_number_is_capped(): void
    {
        $query = SearchQuery::fromRequest(Request::create('/be-nl/search', 'GET', ['q' => 'x', 'page' => '500000']), Market::BeNl);

        // `?page=500000` was a deep OFFSET over the whole match set, on a
        // route anyone can hit.
        $this->assertSame(SearchQuery::MAX_PAGE, $query->page);
    }
}
