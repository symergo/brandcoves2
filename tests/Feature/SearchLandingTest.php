<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ListKind;
use App\Enums\Market;
use App\Models\ProductGroup;
use App\Models\User;
use App\Models\Wishlist;
use App\Models\WishlistItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

    private function saved(User $user, string $brand): void
    {
        $list = Wishlist::query()->firstOrCreate(
            ['owner_user_id' => $user->id, 'kind' => ListKind::Mine->value, 'market' => Market::BeNl->value],
            ['title' => 'Mine'],
        );

        WishlistItem::factory()
            ->of(ProductGroup::factory()->create(['market' => Market::BeNl, 'brand' => $brand]))
            ->create(['wishlist_id' => $list->id]);
    }

    #[Test]
    public function a_bare_search_shows_no_products_and_the_brands_of_what_you_saved(): void
    {
        $user = User::factory()->create();
        $this->saved($user, 'Sony');
        $this->saved($user, 'Sony');
        $this->saved($user, 'Audio-Technica');

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
