<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\Availability;
use App\Enums\Market;
use App\Enums\Source;
use App\Jobs\PullLiveSearch;
use App\Models\ProductGroup;
use App\Services\Connectors\ConnectorRegistry;
use App\Services\Connectors\LiveConnector;
use App\Services\Connectors\Offer;
use App\Services\Search\SearchQuery;
use App\Services\Search\SearchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Search renders from the stored catalogue and never waits on a live shop
 * (2026-09-27), and one search's ordered ids are cached and shared by its
 * pages, its views, and an Inertia visit and a full load of it.
 * See docs/features/search.md.
 */
class SearchLiveQueueTest extends TestCase
{
    use RefreshDatabase;

    /** A live shop that counts how often it is asked. */
    private LiveConnector $shop;

    /**
     * Registered only by the tests about it: while a live fetch is queued the
     * id cache is deliberately not written, which the cache tests would
     * otherwise be measuring instead.
     */
    private function registerShop(): void
    {
        $this->shop = new class implements LiveConnector
        {
            public int $asked = 0;

            public function source(): Source
            {
                return Source::Bol;
            }

            public function supports(Market $market): bool
            {
                return true;
            }

            public function isCoolingDown(): bool
            {
                return false;
            }

            public function search(string $query, Market $market, int $limit = 24): array
            {
                $this->asked++;

                return [new Offer(
                    source: Source::Bol,
                    externalId: '9200000777777',
                    market: $market,
                    title: 'Zeldzame tuinkabouter met lantaarn',
                    affiliateUrl: 'https://www.bol.com/nl/p/kabouter/9200000777777/',
                    price: 2499,
                    imageUrl: 'https://media.bol.com/kabouter.jpg',
                    ean: '8712345000776',
                    availability: Availability::InStock,
                )];
            }

            public function fetchById(string $externalId, Market $market): ?Offer
            {
                return null;
            }

            public function refresh(string $externalId, ?string $ean, Market $market): ?Offer
            {
                return null;
            }
        };

        app(ConnectorRegistry::class)->registerLive($this->shop);
    }

    #[Test]
    public function a_search_renders_without_asking_the_live_shop_and_queues_the_fetch_once(): void
    {
        Queue::fake();
        $this->registerShop();

        $this->get('/be-nl/search?q=tuinkabouter')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('results.total', 0));

        $this->get('/be-nl/search?q=tuinkabouter')->assertOk();

        $this->assertSame(0, $this->shop->asked, 'The page must not wait on a live shop.');

        // Once per window: the second view found the marker the first set.
        Queue::assertPushed(PullLiveSearch::class, 1);
        Queue::assertPushed(PullLiveSearch::class, fn (PullLiveSearch $job) => $job->liveTerm === 'tuinkabouter'
            && $job->market === Market::BeNl);
    }

    #[Test]
    public function the_queued_fetch_stores_what_the_shop_answered_for_the_next_view(): void
    {
        Queue::fake();
        $this->registerShop();

        $this->get('/be-nl/search?q=tuinkabouter')->assertOk();

        $job = Queue::pushed(PullLiveSearch::class)->sole();
        $job->handle(app(SearchService::class));

        $this->assertSame(1, $this->shop->asked);

        // Not cached empty while the fetch was pending, so the next view has it.
        $this->get('/be-nl/search?q=tuinkabouter')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('results.total', 1));
    }

    #[Test]
    public function a_caller_that_waits_gets_the_shop_answer_in_the_same_call(): void
    {
        Queue::fake();
        $this->registerShop();

        $result = app(SearchService::class)->search(
            new SearchQuery(market: Market::BeNl, term: 'tuinkabouter', logged: false),
            waitForLive: true,
        );

        $this->assertSame(1, $this->shop->asked);
        $this->assertSame(1, $result->groups->total());
        Queue::assertNothingPushed();
    }

    #[Test]
    public function page_two_is_served_from_the_cached_ids_without_running_the_search_again(): void
    {
        Queue::fake();
        $this->koptelefoons(30);

        $search = app(SearchService::class);
        $first = $search->search(new SearchQuery(market: Market::BeNl, term: 'koptelefoon', logged: false));

        DB::flushQueryLog();
        DB::enableQueryLog();

        $second = $search->search(new SearchQuery(market: Market::BeNl, term: 'koptelefoon', page: 2, logged: false));

        $this->assertSame([], $this->searchQueries(), 'Page two ran the text search again.');
        $this->assertSame(30, $second->groups->total());
        $this->assertCount(6, $second->groups->items());

        // Same order as the uncached list, and no card on both pages.
        $this->assertSame([], array_intersect(
            array_map(fn (ProductGroup $g) => $g->id, $first->groups->items()),
            array_map(fn (ProductGroup $g) => $g->id, $second->groups->items()),
        ));
    }

    #[Test]
    public function an_inertia_visit_reuses_the_ids_a_full_page_load_cached(): void
    {
        Queue::fake();
        $this->koptelefoons(5);

        $version = $this->get('/be-nl/search?q=koptelefoon')->assertOk()->viewData('page')['version'];

        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->get('/be-nl/search?q=koptelefoon', [
            'X-Inertia' => 'true',
            'X-Inertia-Version' => (string) $version,
        ])->assertOk()->assertJsonPath('props.results.total', 5);

        $this->assertSame([], $this->searchQueries(), 'The Inertia visit ran the text search again.');
    }

    /** The ordered result query, recognised by its relevance order. */
    private function searchQueries(): array
    {
        return array_values(array_filter(
            array_column(DB::getQueryLog(), 'query'),
            fn (string $sql) => str_contains($sql, 'GREATEST(word_similarity('),
        ));
    }

    private function koptelefoons(int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            ProductGroup::factory()->create([
                'market' => Market::BeNl,
                'title' => "Draadloze koptelefoon model {$i}",
                'brand' => 'Aurex',
            ]);
        }
    }
}
