<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\Availability;
use App\Enums\Market;
use App\Enums\ProductStatus;
use App\Enums\Source;
use App\Jobs\PullLiveSearch;
use App\Models\Merchant;
use App\Models\Product;
use App\Models\ProductGroup;
use App\Models\SearchLog;
use App\Services\Search\CoveMatches;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A search whose stored results are thinner than a page asks the live shops
 * in the request, in parallel and bounded; one that fills a page leaves them
 * to the queued job (owner's decision, 2026-09-27). See docs/features/search.md.
 */
class SearchThinLiveTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'giftcoves.connectors.bol.enabled' => true,
            'giftcoves.connectors.bol.client_id' => 'test-id',
            'giftcoves.connectors.bol.client_secret' => 'test-secret',
        ]);

        Cache::flush();

        // The limiter lives in Redis, which Cache::flush() does not reach.
        Redis::del('bc:ratelimit:bol:search', 'bc:ratelimit:bol:search:cooldown');

        Queue::fake();
    }

    /** Calls that reached bol's search, answered or not. */
    private int $asked = 0;

    /** @param  list<array<string, mixed>>|null  $products  null: bol does not answer */
    private function fakeBol(?array $products): void
    {
        Http::fake([
            'login.bol.com/*' => Http::response(['access_token' => 'tok', 'expires_in' => 300]),
            'api.bol.com/*' => function () use ($products) {
                $this->asked++;

                return $products === null
                    ? Http::failedConnection('Operation timed out after 3000 milliseconds')
                    : Http::response(['results' => $products]);
            },
        ]);
    }

    /** @return array<string, mixed> */
    private function bolProduct(): array
    {
        return [
            'bolProductId' => '9200000123456',
            'ean' => '8712345000011',
            'title' => 'Zeldzame tuinkabouter met lantaarn',
            'url' => 'https://www.bol.com/nl/p/kabouter/9200000123456/',
            'image' => ['url' => 'https://media.bol.com/1.jpg'],
            'offer' => ['price' => 24.99],
        ];
    }

    private function searches(): int
    {
        return $this->asked;
    }

    /**
     * The request the browser makes right after a thin search's page: an
     * Inertia partial reload for the deferred `live` prop.
     */
    private function followUp(string $url): TestResponse
    {
        $version = $this->get($url)->viewData('page')['version'];

        return $this->get($url, [
            'X-Inertia' => 'true',
            'X-Inertia-Version' => (string) $version,
            'X-Inertia-Partial-Component' => 'Search',
            'X-Inertia-Partial-Data' => 'live',
        ])->assertOk();
    }

    /** A product as the catalogue holds one: a group and an active offer in it. */
    private function storedProduct(string $title): ProductGroup
    {
        $group = ProductGroup::factory()->create(['market' => Market::BeNl, 'title' => $title]);
        $merchant = Merchant::firstOrCreate(['source' => Source::Awin->value, 'external_id' => 'thinshop'], ['name' => 'Thinshop BE']);

        Product::create([
            'source' => Source::Awin->value,
            'external_id' => 'thin-'.$group->id,
            'market' => Market::BeNl->value,
            'merchant_id' => $merchant->id,
            'group_id' => $group->id,
            'title' => $title,
            'price' => 2499,
            'affiliate_url' => 'https://example.test/thin-'.$group->id,
            'availability' => Availability::InStock->value,
            'status' => ProductStatus::Active->value,
        ]);

        return $group;
    }

    /**
     * A person no longer waits for bol (2026-10-25): the page comes at once
     * with what is stored and names the shop it is asking, and the deferred
     * `live` prop, which the browser requests straight after, brings bol's
     * products and says how many were added.
     */
    #[Test]
    public function a_term_with_nothing_stored_shows_bols_products_right_after_the_first_view(): void
    {
        $this->fakeBol([$this->bolProduct()]);

        $this->get('/be-nl/search?q=tuinkabouter')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('results.items', 0)
                ->where('liveShops', ['bol.com'])
                ->missing('live'));

        $this->assertSame(0, $this->searches(), 'The first response must not wait for bol.');

        $live = $this->followUp('/be-nl/search?q=tuinkabouter')->json('props.live');

        $this->assertCount(1, $live['results']['items']);
        $this->assertSame(1, $live['added']);
        $this->assertSame('1 product van bol.com toegevoegd', $live['notice']);
        $this->assertSame(1, $this->searches());

        // Asked for the words the visitor typed.
        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/products/search')
            && $r['search-term'] === 'tuinkabouter');

        Queue::assertNotPushed(PullLiveSearch::class);
    }

    #[Test]
    public function a_term_that_fills_a_page_renders_without_asking_and_queues_the_fetch(): void
    {
        $this->fakeBol([$this->bolProduct()]);

        // With an offer each: since 2026-10-25 "thin" is decided by the word
        // match, which reads the offers' text, as every stored product has.
        for ($i = 0; $i < 24; $i++) {
            $this->storedProduct("Tuinkabouter model {$i}");
        }

        $this->get('/be-nl/search?q=tuinkabouter')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('results.items', 24));

        $this->assertSame(0, $this->searches());
        Queue::assertPushed(PullLiveSearch::class, 1);
    }

    /**
     * When a live shop answers for the term, the fuzzy title match is skipped
     * once the words find at least 6 products (owner, 2026-10-25): its near
     * misses are not worth 2-3 s then. Below 6 it still fills the page, see
     * the next test. "Tuinkabauter" is a stored title only the fuzzy match
     * finds for "tuinkabouter".
     */
    #[Test]
    public function when_a_live_shop_answers_and_the_words_find_six_the_fuzzy_match_is_skipped(): void
    {
        $this->fakeBol([$this->bolProduct()]);
        $this->storedProduct('Tuinkabauter met hengel');
        // Five stored by their words, and bol's sixth.
        foreach (range(1, 5) as $i) {
            $this->storedProduct("Tuinkabouter model {$i}");
        }

        $titles = array_column($this->followUp('/be-nl/search?q=tuinkabouter')->json('props.live.results.items'), 'title');

        $this->assertContains('Zeldzame tuinkabouter met lantaarn', $titles);
        $this->assertNotContains('Tuinkabauter met hengel', $titles);
    }

    /** Fewer than 6 by their words after the shop answered: the fuzzy match still fills the page. */
    #[Test]
    public function when_a_live_shop_answers_but_the_words_find_fewer_than_six_the_fuzzy_match_runs(): void
    {
        $this->fakeBol([$this->bolProduct()]);
        $this->storedProduct('Tuinkabauter met hengel');

        $titles = array_column($this->followUp('/be-nl/search?q=tuinkabouter')->json('props.live.results.items'), 'title');

        $this->assertContains('Zeldzame tuinkabouter met lantaarn', $titles);
        $this->assertContains('Tuinkabauter met hengel', $titles);
    }

    /**
     * A shop's own search returns products whose titles need not contain the
     * word. When its answer leaves the word match empty, the fuzzy match runs
     * after all: on production "kabouterlamp" went from 24 results to none
     * (2026-10-25) before this.
     */
    #[Test]
    public function when_the_shops_answer_matches_no_words_the_fuzzy_match_still_runs(): void
    {
        $this->fakeBol([[...$this->bolProduct(), 'title' => 'Groot tuinbeeld met lantaarn']]);
        $this->storedProduct('Tuinkabauter met hengel');

        $titles = array_column($this->followUp('/be-nl/search?q=tuinkabouter')->json('props.live.results.items'), 'title');

        $this->assertContains('Tuinkabauter met hengel', $titles);
    }

    #[Test]
    public function when_the_live_shop_finds_nothing_the_fuzzy_match_still_runs(): void
    {
        $this->fakeBol([]);
        $this->storedProduct('Tuinkabauter met hengel');

        $titles = array_column($this->followUp('/be-nl/search?q=tuinkabouter')->json('props.live.results.items'), 'title');

        $this->assertSame(['Tuinkabauter met hengel'], $titles);
    }

    /**
     * A crawler sees what is stored and costs no live call (owner,
     * 2026-10-25), except a search engine: see the next test.
     */
    #[Test]
    public function a_crawler_is_not_sent_to_the_live_shops(): void
    {
        $this->fakeBol([$this->bolProduct()]);

        $this->get('/be-nl/search?q=tuinkabouter', ['User-Agent' => 'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko; compatible; GPTBot/1.2; +https://openai.com/gptbot)'])
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('results.items', 0));

        $this->assertSame(0, $this->searches());
        Queue::assertNotPushed(PullLiveSearch::class);
    }

    /**
     * Google and Bing still get the shops' products, in the page itself: what
     * they see is what they index, and a person would see them. Otherwise a
     * thin term is an indexable empty page for them alone.
     */
    #[Test]
    public function a_search_engine_sees_the_shops_products_like_a_person(): void
    {
        $this->fakeBol([$this->bolProduct()]);

        $this->get('/be-nl/search?q=tuinkabouter', ['User-Agent' => 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)'])
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('results.items', 1));

        $this->assertSame(1, $this->searches());
    }

    #[Test]
    public function a_shop_that_times_out_leaves_the_stored_results_and_queues_the_fetch(): void
    {
        $this->fakeBol(null);

        ProductGroup::factory()->create(['market' => Market::BeNl, 'title' => 'Tuinkabouter met hengel']);

        $live = $this->followUp('/be-nl/search?q=tuinkabouter')->json('props.live');

        // The stored product is still there, nothing was added.
        $this->assertCount(1, $live['results']['items']);
        $this->assertSame(0, $live['added']);
        $this->assertNull($live['notice']);

        // Asked once: no retry on this path.
        $this->assertSame(1, $this->searches());
        Queue::assertPushed(PullLiveSearch::class, 1);
    }

    /**
     * The by-store view is drawn from lanes, not from the results, so the
     * follow-up sends them again; before that the shops' products never
     * reached it, and a store view that started empty stayed empty.
     */
    #[Test]
    public function the_store_view_gets_the_shops_products_too(): void
    {
        $this->fakeBol([$this->bolProduct()]);

        $live = $this->followUp('/be-nl/search?q=tuinkabouter&view=store')->json('props.live');

        $this->assertNotNull($live['lanes']);
        $titles = collect($live['lanes'])->flatMap(fn (array $lane) => array_column($lane['items'], 'title'))->all();
        $this->assertContains('Zeldzame tuinkabouter met lantaarn', $titles);
    }

    /** In the grid view nothing extra is computed for lanes. */
    #[Test]
    public function the_grid_view_gets_no_lanes(): void
    {
        $this->fakeBol([$this->bolProduct()]);

        $this->assertNull($this->followUp('/be-nl/search?q=tuinkabouter')->json('props.live.lanes'));
    }

    /**
     * The follow-up returns `live` and computes nothing else of the page.
     * Checked on the Coves row: after the first request, CoveMatches is
     * replaced by a fake that must not be called. (A query count would not
     * do: the full page's extras are partly cached by then.)
     */
    #[Test]
    public function the_follow_up_computes_only_the_live_answer(): void
    {
        $this->fakeBol([$this->bolProduct()]);
        $url = '/be-nl/search?q=tuinkabouter';
        $version = $this->get($url)->viewData('page')['version'];

        $this->mock(CoveMatches::class, fn ($mock) => $mock->shouldNotReceive('for'));

        $this->get($url, [
            'X-Inertia' => 'true',
            'X-Inertia-Version' => (string) $version,
            'X-Inertia-Partial-Component' => 'Search',
            'X-Inertia-Partial-Data' => 'live',
        ])->assertOk()->assertJsonPath('props.live.added', 1);
    }

    /** The follow-up runs the search again; it must not count the person twice. */
    #[Test]
    public function the_follow_up_request_is_not_logged_a_second_time(): void
    {
        $this->fakeBol([$this->bolProduct()]);

        // A person: the log wants the session cookie a crawler never carries.
        $this->withCookie((string) config('session.cookie'), 'a-visitor');
        $this->followUp('/be-nl/search?q=tuinkabouter');

        $this->assertSame(1, (int) SearchLog::query()->where('query', 'tuinkabouter')->sum('search_count'));
    }
}
