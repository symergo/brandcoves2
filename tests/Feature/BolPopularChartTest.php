<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\Market;
use App\Services\Connectors\Bol\BolConnector;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * bol's bestseller chart — the demand signal.
 *
 * The contract is the same as search: degrade, never throw. The one addition
 * that matters is rank. A chart with the right products in the wrong order is
 * worse than no chart, because every downstream use — the discovery retriever's
 * novelty signal, the trends page, the topic miner — reads movement, and
 * movement is a difference of two ranks.
 */
class BolPopularChartTest extends TestCase
{
    private BolConnector $connector;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'giftcoves.connectors.bol.enabled' => true,
            'giftcoves.connectors.bol.client_id' => 'test-id',
            'giftcoves.connectors.bol.client_secret' => 'test-secret',
            'giftcoves.connectors.bol.partner_site_id' => ['BE' => '25421', 'NL' => '1005548'],
            'giftcoves.connectors.bol.popular.enabled' => true,
            'giftcoves.connectors.bol.popular.page_size' => 2,
            'giftcoves.connectors.bol.popular.pages' => 2,
            'giftcoves.connectors.bol.popular.rate' => 8.0,
            'giftcoves.connectors.bol.popular.burst' => 8,
        ]);

        Cache::flush();

        // The rate limiter talks to Redis directly on purpose — sharing state
        // across processes is its whole job — so Cache::flush() does not reset
        // it, and a drained bucket makes later tests silently send nothing.
        Redis::del('bc:ratelimit:bol:popular', 'bc:ratelimit:bol:popular:cooldown');

        $this->connector = new BolConnector;
    }

    /** @return array<string, mixed> */
    private function fakeToken(): array
    {
        return ['login.bol.com/*' => Http::response(['access_token' => 'tok', 'expires_in' => 300])];
    }

    /** @return array<string, mixed> */
    private function product(string $id, string $title): array
    {
        return [
            'bolProductId' => $id,
            'ean' => '400638133393'.substr($id, -1),
            'title' => $title,
            'url' => "https://www.bol.com/nl/p/x/{$id}/",
            'image' => ['url' => 'https://media.bol.com/1.jpg'],
            'gpc' => [['level' => 'CHUNK', 'name' => 'Koptelefoon']],
            'offer' => ['price' => 99.95],
        ];
    }

    /**
     * bol's actual chart response.
     *
     * Copied from a live call, not from the docs and not from what our parser
     * happens to expect: `results` for the products, `allRelevantCategories`
     * for the taxonomy, and rows carrying `categoryId` / `categoryName` /
     * `productCount` / `subcategories`.
     *
     * The first version of this fixture used `categories` / `id` / `name` /
     * `count`, and so did the parser — so the pair agreed with each other and
     * with nothing else. Green test, zero categories discovered, and a crawl
     * silently pinned to the market-wide chart forever. Exactly the failure the
     * search connector already carries a comment about.
     *
     * @param  list<array<string, mixed>>  $products
     * @param  list<array<string, mixed>>  $categories
     * @return array<string, mixed>
     */
    private function chart(array $products, array $categories = []): array
    {
        return [
            'page' => 1,
            'resultsPerPage' => count($products),
            'results' => $products,
            'allRelevantCategories' => $categories,
        ];
    }

    /**
     * One category row, in bol's shape.
     *
     * @param  list<array<string, mixed>>  $subcategories
     * @return array<string, mixed>
     */
    private function category(string $id, string $name, array $subcategories = [], int $productCount = 0): array
    {
        return [
            'categoryId' => $id,
            'categoryName' => $name,
            'subcategories' => $subcategories,
            'productCount' => $productCount,
        ];
    }

    #[Test]
    public function it_returns_entries_in_rank_order(): void
    {
        Http::fake([...$this->fakeToken(), 'api.bol.com/*' => Http::sequence()
            ->push($this->chart([$this->product('1', 'First'), $this->product('2', 'Second')]))
            ->push($this->chart([$this->product('3', 'Third')])),
        ]);

        $chart = $this->connector->popular(Market::BeNl, null, 50);

        $this->assertCount(3, $chart->entries);

        // Rank 1 is the top, and it continues across pages — a chart that
        // restarted at 1 on page two would report every product as if it were
        // the bestseller.
        $this->assertSame([1, 2, 3], array_map(fn ($e) => $e->rank, $chart->entries));
        $this->assertSame('First', $chart->entries[0]->offer->title);
        $this->assertSame('Third', $chart->entries[2]->offer->title);
    }

    #[Test]
    public function an_unstorable_row_still_consumes_its_rank(): void
    {
        Http::fake([...$this->fakeToken(), 'api.bol.com/*' => Http::sequence()
            // The middle row has no URL, so it has no affiliate link and
            // Offer::isValid() rejects it.
            ->push($this->chart([
                $this->product('1', 'First'),
                array_merge($this->product('2', 'Broken'), ['url' => '']),
            ]))
            ->push($this->chart([])),
        ]);

        $chart = $this->connector->popular(Market::BeNl, null, 50);

        $this->assertCount(1, $chart->entries);

        /*
         * Rank counts every row bol returned, including the ones we cannot
         * store. Renumbering would promote the next product into a position it
         * never held — and since movement is a difference of ranks, one dropped
         * row would fabricate a climb for everything below it.
         */
        $this->assertSame(1, $chart->entries[0]->rank);
    }

    #[Test]
    public function a_product_repeated_across_pages_is_kept_once_at_its_best_rank(): void
    {
        Http::fake([...$this->fakeToken(), 'api.bol.com/*' => Http::sequence()
            ->push($this->chart([$this->product('1', 'First'), $this->product('2', 'Second')]))
            ->push($this->chart([$this->product('1', 'First again'), $this->product('3', 'Third')])),
        ]);

        $chart = $this->connector->popular(Market::BeNl, null, 50);

        /*
         * bol's "popular" list is the whole catalogue in popularity order and
         * the ordering is not stable between requests, so paging returns some
         * products twice. Found on the first live run, where the daily rank
         * upsert died on "ON CONFLICT DO UPDATE command cannot affect row a
         * second time".
         *
         * First sighting wins: a product at #1 and again at #3 is at #1, and
         * keeping the later one would report a fall that never happened.
         */
        $this->assertSame(['1', '2', '3'], array_map(fn ($e) => $e->offer->externalId, $chart->entries));
        $this->assertSame([1, 2, 4], array_map(fn ($e) => $e->rank, $chart->entries));
    }

    #[Test]
    public function it_discovers_categories_from_the_response(): void
    {
        Http::fake([...$this->fakeToken(), 'api.bol.com/*' => Http::response($this->chart(
            [$this->product('1', 'First')],
            [
                $this->category('4770', 'Koptelefoons', productCount: 812),
                // Nameless: unusable as a topic or a label, and dropped rather
                // than stored as a blank row.
                $this->category('9999', ''),
            ],
        ))]);

        $chart = $this->connector->popular(Market::BeNl, null, 50);

        $this->assertCount(1, $chart->categories);
        $this->assertSame('4770', $chart->categories[0]->externalId);
        $this->assertSame('Koptelefoons', $chart->categories[0]->name);
        $this->assertSame(812, $chart->categories[0]->productCount);
    }

    #[Test]
    public function nested_subcategories_are_taken_with_their_parent(): void
    {
        Http::fake([...$this->fakeToken(), 'api.bol.com/*' => Http::response($this->chart(
            [$this->product('1', 'First')],
            [$this->category('11764', 'Koken & Tafelen', [
                $this->category('4770', 'Pannen'),
            ])],
        ))]);

        $chart = $this->connector->popular(Market::BeNl, null, 50);

        // A request we have already paid for hands us the next tier of the
        // crawl and its parentage for free.
        $this->assertCount(2, $chart->categories);
        $this->assertNull($chart->categories[0]->parentExternalId);
        $this->assertSame('4770', $chart->categories[1]->externalId);
        $this->assertSame('11764', $chart->categories[1]->parentExternalId);
    }

    #[Test]
    public function a_zero_product_count_is_recorded_as_unknown(): void
    {
        Http::fake([...$this->fakeToken(), 'api.bol.com/*' => Http::response($this->chart(
            [$this->product('1', 'First')],
            [$this->category('4770', 'Koptelefoons')],
        ))]);

        $chart = $this->connector->popular(Market::BeNl, null, 50);

        // bol returns productCount = 0 on every row of the market-wide chart.
        // Storing that as a real zero would mark the entire taxonomy empty and
        // make "how big is this category" unanswerable.
        $this->assertNull($chart->categories[0]->productCount);
    }

    #[Test]
    public function the_request_carries_the_country_language_and_includes(): void
    {
        Http::fake([...$this->fakeToken(), 'api.bol.com/*' => Http::response($this->chart([]))]);

        $this->connector->popular(Market::BeFr, '4770', 50);

        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/products/lists/popular')
            && str_contains($r->url(), 'country-code=BE')
            && str_contains($r->url(), 'category-id=4770')
            // Without the includes bol returns catalogue entries with no price
            // and no image, and OfferUpserter would reject every one of them.
            && str_contains($r->url(), 'include-offer=true')
            && str_contains($r->url(), 'include-image=true')
            // The only way to learn a category id at all.
            && str_contains($r->url(), 'include-relevant-categories=true')
            && $r->header('Accept-Language')[0] === 'fr-BE');
    }

    #[Test]
    public function the_market_wide_chart_sends_no_category(): void
    {
        Http::fake([...$this->fakeToken(), 'api.bol.com/*' => Http::response($this->chart([]))]);

        $this->connector->popular(Market::BeNl, null, 50);

        // Not `category-id=` with an empty value, which bol reads as a filter on
        // a category that does not exist.
        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/products/lists/popular')
            && ! str_contains($r->url(), 'category-id'));
    }

    #[Test]
    public function an_unrecognised_envelope_is_logged_rather_than_silently_empty(): void
    {
        /*
         * The response envelope for this endpoint is undocumented.
         *
         * If bol renames the key, every code path downstream keeps working and
         * reports "no bestsellers today", forever. An empty chart and a wrong
         * parser are indistinguishable from the outside — this is the shape of
         * bug that survives a green suite for months, so it has to be loud.
         */
        Log::spy();

        Http::fake([...$this->fakeToken(), 'api.bol.com/*' => Http::response([
            'page' => 1,
            'items' => [$this->product('1', 'First')],
        ])]);

        $chart = $this->connector->popular(Market::BeNl, null, 50);

        $this->assertTrue($chart->isEmpty());

        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $message) => str_contains($message, 'unrecognised envelope'))
            ->atLeast()->once();
    }

    #[Test]
    public function spain_gets_no_chart_and_no_request(): void
    {
        Http::fake($this->fakeToken());

        // bol does not operate there. Asking anyway would spend a request to be
        // told nothing.
        $this->assertTrue($this->connector->popular(Market::Es, null, 50)->isEmpty());

        Http::assertNothingSent();
    }

    #[Test]
    public function charts_can_be_switched_off_without_disabling_search(): void
    {
        config(['giftcoves.connectors.bol.popular.enabled' => false]);
        Http::fake($this->fakeToken());

        $this->assertTrue($this->connector->popular(Market::BeNl, null, 50)->isEmpty());
        Http::assertNothingSent();

        // Search is a separate capability and stays on: the chart runs on a
        // schedule and costs requests, and turning it off must not take the
        // visitor-facing source down with it.
        $this->assertTrue($this->connector->supports(Market::BeNl));
    }

    #[Test]
    public function an_upstream_error_degrades_instead_of_throwing(): void
    {
        Http::fake([...$this->fakeToken(), 'api.bol.com/*' => Http::response([], 503)]);

        $this->assertTrue($this->connector->popular(Market::BeNl, null, 50)->isEmpty());
    }

    #[Test]
    public function a_429_cools_the_chart_bucket_down_on_its_own(): void
    {
        Http::fake([...$this->fakeToken(), 'api.bol.com/*' => Http::response([], 429)]);

        $this->assertTrue($this->connector->popular(Market::BeNl, null, 50)->isEmpty());

        $this->assertTrue($this->connector->isChartCoolingDown());

        // Separate buckets: background chart work backing off must not stop a
        // visitor's search, which is the whole reason the limiter is per
        // endpoint.
        $this->assertFalse($this->connector->isCoolingDown());
    }

    #[Test]
    public function it_stops_paging_when_a_page_comes_back_short(): void
    {
        Http::fake([...$this->fakeToken(), 'api.bol.com/*' => Http::sequence()
            // page_size is 2; one row means the chart is over.
            ->push($this->chart([$this->product('1', 'Only')]))
            ->push($this->chart([$this->product('2', 'Should never be asked for')])),
        ]);

        $chart = $this->connector->popular(Market::BeNl, null, 50);

        $this->assertCount(1, $chart->entries);
        // Token + one chart page. Asking for page two of a one-page list spends
        // a request from a rate-limited budget to learn nothing.
        Http::assertSentCount(2);
    }
}
