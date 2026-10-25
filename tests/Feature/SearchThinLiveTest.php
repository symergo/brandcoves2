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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
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

    #[Test]
    public function a_term_with_nothing_stored_shows_bols_products_on_the_first_view(): void
    {
        $this->fakeBol([$this->bolProduct()]);

        $this->get('/be-nl/search?q=tuinkabouter')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('results.items', 1));

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
     * (owner, 2026-10-25): its near misses are not worth 2-3 s once the shop
     * supplied real products. "Tuinkabauter" is a stored title only the fuzzy
     * match finds for "tuinkabouter".
     */
    #[Test]
    public function when_a_live_shop_answers_the_fuzzy_match_is_skipped(): void
    {
        $this->fakeBol([$this->bolProduct()]);
        $this->storedProduct('Tuinkabauter met hengel');

        $titles = array_column($this->get('/be-nl/search?q=tuinkabouter')->assertOk()->viewData('page')['props']['results']['items'], 'title');

        $this->assertContains('Zeldzame tuinkabouter met lantaarn', $titles);
        $this->assertNotContains('Tuinkabauter met hengel', $titles);
    }

    #[Test]
    public function when_the_live_shop_finds_nothing_the_fuzzy_match_still_runs(): void
    {
        $this->fakeBol([]);
        $this->storedProduct('Tuinkabauter met hengel');

        $titles = array_column($this->get('/be-nl/search?q=tuinkabouter')->assertOk()->viewData('page')['props']['results']['items'], 'title');

        $this->assertSame(['Tuinkabauter met hengel'], $titles);
    }

    /** A crawler sees what is stored and costs no live call (owner, 2026-10-25). */
    #[Test]
    public function a_crawler_is_not_sent_to_the_live_shops(): void
    {
        $this->fakeBol([$this->bolProduct()]);

        $this->get('/be-nl/search?q=tuinkabouter', ['User-Agent' => 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)'])
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('results.items', 0));

        $this->assertSame(0, $this->searches());
        Queue::assertNotPushed(PullLiveSearch::class);
    }

    #[Test]
    public function a_shop_that_times_out_leaves_the_stored_results_and_queues_the_fetch(): void
    {
        $this->fakeBol(null);

        ProductGroup::factory()->create(['market' => Market::BeNl, 'title' => 'Tuinkabouter met hengel']);

        $this->get('/be-nl/search?q=tuinkabouter')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('results.items', 1));

        // Asked once: no retry on this path.
        $this->assertSame(1, $this->searches());
        Queue::assertPushed(PullLiveSearch::class, 1);
    }
}
