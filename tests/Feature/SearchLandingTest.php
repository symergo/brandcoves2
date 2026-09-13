<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ListKind;
use App\Enums\Market;
use App\Models\BrandStat;
use App\Models\ProductGroup;
use App\Models\User;
use App\Models\Wishlist;
use App\Models\WishlistItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * `/search` before a search: ways in, not products (2026-09-13).
 *
 * A grid under an empty box answered a question nobody asked. The landing
 * carries the recent searches, the brands on the visitor's own lists and the
 * site's other ways of finding something; the grid is for a term.
 */
class SearchLandingTest extends TestCase
{
    use RefreshDatabase;

    private function saved(User $user, string $brand, Market $market = Market::BeNl): void
    {
        $list = Wishlist::query()->firstOrCreate(
            ['owner_user_id' => $user->id, 'kind' => ListKind::Mine->value, 'market' => $market->value],
            ['title' => 'Mine'],
        );

        WishlistItem::factory()
            ->of(ProductGroup::factory()->create(['market' => $market, 'brand' => $brand]))
            ->create(['wishlist_id' => $list->id]);
    }

    /** A brand page exists only for a brand with three products in this market. */
    private function page(string $brand, Market $market = Market::BeNl): void
    {
        BrandStat::create([
            'market' => $market->value,
            'brand' => $brand,
            'slug' => Str::slug($brand),
            'aliases' => [$brand],
            'product_count' => 3,
        ]);
    }

    #[Test]
    public function a_bare_search_shows_no_products_and_the_brands_of_what_you_saved(): void
    {
        $user = User::factory()->create();
        $this->saved($user, 'Sony');
        $this->saved($user, 'Sony');
        $this->saved($user, 'Audio-Technica');
        $this->page('Sony');
        $this->page('Audio-Technica');

        // Products exist; none are shown until somebody asks for them.
        ProductGroup::factory()->count(3)->create(['market' => Market::BeNl]);

        $this->actingAs($user)->get('/be-nl/search')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('results.total', 0)
                ->where('results.items', [])
                ->has('landing.recentSearches')
                // Most saved first, folded to the brand page's slug.
                ->where('landing.brands.0.name', 'Sony')
                ->where('landing.brands.0.url', '/be-nl/brand/sony')
                ->where('landing.brands.1.name', 'Audio-Technica')
                ->where('landing.brands.1.url', '/be-nl/brand/audio-technica'));
    }

    /**
     * The report of 2026-09-13: one saved AIR&ME product, no brand page (three
     * products short of one), and a chip that led to /brand/airme and a 404.
     * A brand with no page in this market goes to the search filtered on it,
     * which shows the very thing that was saved.
     */
    #[Test]
    public function a_saved_brand_without_a_page_goes_to_the_search_for_it_rather_than_a_404(): void
    {
        $user = User::factory()->create();
        $this->saved($user, 'AIR&ME');
        $this->saved($user, 'Sony');
        $this->page('Sony');
        // A page in another market is not a page here.
        $this->page('AIR&ME', Market::NlNl);

        $this->actingAs($user)->get('/be-nl/search')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('landing.brands.0.name', 'AIR&ME')
                ->where('landing.brands.0.url', '/be-nl/search?brand%5B0%5D=AIR%26ME')
                ->where('landing.brands.1.url', '/be-nl/brand/sony'));

        $this->actingAs($user)->get('/be-nl/brand/airme')->assertNotFound();
        $this->actingAs($user)->get('/be-nl/search?brand[]=AIR%26ME')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('results.total', 1));
    }

    /** Two spellings of one brand are one chip, and the fallback search carries both. */
    #[Test]
    public function a_brand_saved_under_two_spellings_is_one_chip_that_searches_for_both(): void
    {
        $user = User::factory()->create();
        $this->saved($user, 'Audio-Technica');
        $this->saved($user, 'Audio Technica');

        $this->actingAs($user)->get('/be-nl/search')
            ->assertInertia(fn ($page) => $page
                ->count('landing.brands', 1)
                ->where('landing.brands.0.name', 'Audio-Technica')
                ->where('landing.brands.0.url', '/be-nl/search?'.http_build_query(['brand' => ['Audio-Technica', 'Audio Technica']])));
    }

    /**
     * The report of 2026-09-14: Melitta, Scanpart and Teltonika saved on
     * be-nl, the landing opened on en, three chips to a search that found
     * nothing, because en does not sell them. A saved brand is a chip only
     * where the market has a product of it; where it was saved does not
     * matter, so JBL saved on be-nl is still a chip on en.
     */
    #[Test]
    public function a_saved_brand_is_a_chip_only_in_a_market_that_sells_it(): void
    {
        $user = User::factory()->create();
        $this->saved($user, 'Melitta');
        $this->saved($user, 'Melitta');
        $this->saved($user, 'JBL');
        // Sold on en too, under a spelling the lists do not carry.
        ProductGroup::factory()->create(['market' => Market::En, 'brand' => 'jbl']);
        // A product the search would never show does not make a brand sold here.
        ProductGroup::factory()->create(['market' => Market::En, 'brand' => 'Melitta', 'image_url' => null]);

        $this->actingAs($user)->get('/en/search')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->count('landing.brands', 1)
                ->where('landing.brands.0.name', 'JBL')
                // The search filters on the spelling the market stores, not the one the list holds.
                ->where('landing.brands.0.url', '/en/search?'.http_build_query(['brand' => ['jbl']])));

        $this->actingAs($user)->get('/en/search?brand[]=jbl')
            ->assertInertia(fn ($page) => $page->where('results.total', 1));

        // Where it was saved, Melitta leads as before.
        $this->actingAs($user)->get('/be-nl/search')
            ->assertInertia(fn ($page) => $page
                ->count('landing.brands', 2)
                ->where('landing.brands.0.name', 'Melitta'));
    }

    #[Test]
    public function a_stranger_gets_the_landing_without_brands(): void
    {
        $this->get('/be-nl/search')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('results.total', 0)
                ->where('landing.brands', []));
    }

    #[Test]
    public function a_term_or_a_filter_is_a_search_and_gets_the_grid(): void
    {
        ProductGroup::factory()->create(['market' => Market::BeNl, 'title' => 'Sony koptelefoon']);

        $this->get('/be-nl/search?q=sony')
            ->assertInertia(fn ($page) => $page->where('landing', null));

        $this->get('/be-nl/search?min=10')
            ->assertInertia(fn ($page) => $page->where('landing', null));
    }
}
