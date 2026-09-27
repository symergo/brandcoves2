<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\Market;
use App\Enums\Source;
use App\Models\Merchant;
use App\Models\ProductGroup;
use App\Services\Search\SearchQuery;
use App\Services\Search\SearchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * One search lists at most 25 products per shop, counted by the shop behind
 * each product's best offer, in ranking order; and it counts no total, so the
 * page says only which page it is and whether another follows (owner's
 * decisions, 2026-09-27). See docs/features/search.md.
 */
class SearchShopCapTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
    }

    #[Test]
    public function a_shop_contributes_at_most_twenty_five_products_in_ranking_order(): void
    {
        $big = $this->shop('Grote Winkel');
        $small = $this->shop('Kleine Winkel');

        // Cheapest first under `price_asc`, so the ranking is known: the big
        // shop's 30 are the 30 cheapest, the small shop's 3 come after.
        $bigIds = [];
        for ($i = 0; $i < 30; $i++) {
            $bigIds[] = $this->product($big, "Koptelefoon groot {$i}", 1000 + $i);
        }
        $smallIds = [];
        for ($i = 0; $i < 3; $i++) {
            $smallIds[] = $this->product($small, "Koptelefoon klein {$i}", 5000 + $i);
        }

        $search = app(SearchService::class);
        $query = fn (int $page) => new SearchQuery(market: Market::BeNl, term: 'koptelefoon', sort: 'price_asc', page: $page, logged: false);

        $first = $search->search($query(1));
        $second = $search->search($query(2));

        $shown = array_map(fn (ProductGroup $g) => $g->id, [...$first->groups->items(), ...$second->groups->items()]);

        // The first 25 of the big shop, in order, then the small shop's three.
        $this->assertSame([...array_slice($bigIds, 0, 25), ...$smallIds], $shown);
        $this->assertTrue($first->groups->hasMorePages());
        $this->assertFalse($second->groups->hasMorePages());
    }

    #[Test]
    public function the_page_carries_no_total_and_pages_by_whether_more_follow(): void
    {
        $shop = $this->shop('Winkel');
        for ($i = 0; $i < 25; $i++) {
            $this->product($shop, "Koptelefoon {$i}", 1000 + $i);
        }

        $this->get('/be-nl/search?q=koptelefoon')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->missing('results.total')
                ->missing('results.lastPage')
                ->where('results.empty', false)
                ->where('results.currentPage', 1)
                ->where('results.hasMore', true)
                ->has('results.items', 24));

        $this->get('/be-nl/search?q=koptelefoon&page=2')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('results.hasMore', false)
                ->has('results.items', 1));

        // A search that finds nothing still says so, without a number.
        $this->get('/be-nl/search?q=zzqxv')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('results.empty', true)->missing('results.total'));
    }

    private function shop(string $name): Merchant
    {
        return Merchant::query()->create([
            'source' => Source::Awin->value,
            'external_id' => (string) random_int(1000, 999999),
            'name' => $name,
        ]);
    }

    /** A product whose best (only) offer is this shop's; returns the group id. */
    private function product(Merchant $shop, string $title, int $price): int
    {
        $group = ProductGroup::factory()->create([
            'market' => Market::BeNl,
            'title' => $title,
            'min_price' => $price,
        ]);

        $offerId = DB::table('products')->insertGetId([
            'source' => Source::Awin->value,
            'external_id' => 'aw-'.$group->id,
            'market' => Market::BeNl->value,
            'group_id' => $group->id,
            'merchant_id' => $shop->id,
            'title' => $title,
            'affiliate_url' => 'https://track.example/'.$group->id,
            'price' => $price,
            'status' => 'active',
            'first_seen_at' => now(),
            'last_seen_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $group->forceFill(['best_offer_id' => $offerId])->save();

        return $group->id;
    }
}
