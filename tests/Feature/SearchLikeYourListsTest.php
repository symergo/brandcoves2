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
 * The search landing, seeded from what the visitor saved (2026-09-13).
 *
 * `/search` with no term is a catalogue grid, the same for everybody. For a
 * signed-in visitor with products on a list it is led by the brands and
 * categories of those products instead, and what is already on a list is
 * left out. A term, a filter, or no account gives the ordinary grid.
 */
class SearchLikeYourListsTest extends TestCase
{
    use RefreshDatabase;

    private function group(string $brand, string $category, Market $market = Market::BeNl, ?string $identity = null): ProductGroup
    {
        return ProductGroup::factory()->create(array_filter([
            'market' => $market,
            'brand' => $brand,
            'category' => $category,
            'identity_key' => $identity,
        ], fn ($v) => $v !== null));
    }

    private function saved(User $user, ProductGroup $group): void
    {
        $list = Wishlist::query()->firstOrCreate(
            ['owner_user_id' => $user->id, 'kind' => ListKind::Mine->value, 'market' => Market::BeNl->value],
            ['title' => 'Mine'],
        );

        WishlistItem::factory()->of($group)->create(['wishlist_id' => $list->id]);
    }

    #[Test]
    public function a_bare_search_is_led_by_the_brands_and_categories_you_saved(): void
    {
        $user = User::factory()->create();

        $saved = $this->group('Sony', 'audio');
        $this->saved($user, $saved);

        $sameBrandAndCategory = $this->group('Sony', 'audio');
        $sameCategory = $this->group('Bose', 'audio');
        $sameBrand = $this->group('Sony', 'tv');
        $this->group('Sony', 'audio');
        $unrelated = $this->group('Lego', 'toys');

        $ids = fn ($page) => collect($page->toArray()['props']['results']['items'])->pluck('id')->all();

        $this->actingAs($user)->get('/be-nl/search')
            ->assertOk()
            ->assertInertia(function ($page) use ($ids, $saved, $sameBrandAndCategory, $sameCategory, $sameBrand, $unrelated): void {
                $page->where('seeded', 'lists');
                $shown = $ids($page);

                // Both matches first, then one of each; nothing unrelated, and
                // not the thing already on the list.
                $this->assertSame($sameBrandAndCategory->id, $shown[0]);
                $this->assertContains($sameCategory->id, $shown);
                $this->assertContains($sameBrand->id, $shown);
                $this->assertNotContains($unrelated->id, $shown);
                $this->assertNotContains($saved->id, $shown);
            });
    }

    #[Test]
    public function a_product_saved_on_another_market_seeds_this_one_and_its_twin_is_left_out(): void
    {
        $user = User::factory()->create();

        $belgian = $this->group('Sony', 'audio', Market::BeNl, 'ean:1234567890123');
        $this->saved($user, $belgian);

        $dutchTwin = $this->group('Sony', 'audio', Market::NlNl, 'ean:1234567890123');
        $dutchSibling = $this->group('Sony', 'audio', Market::NlNl);
        $this->group('Sony', 'audio', Market::NlNl);
        $this->group('Sony', 'audio', Market::NlNl);
        $this->group('Bose', 'audio', Market::NlNl);

        $this->actingAs($user)->get('/nl-nl/search')
            ->assertInertia(function ($page) use ($dutchTwin, $dutchSibling): void {
                $page->where('seeded', 'lists');
                $shown = collect($page->toArray()['props']['results']['items'])->pluck('id')->all();

                $this->assertContains($dutchSibling->id, $shown);
                $this->assertNotContains($dutchTwin->id, $shown);
            });
    }

    #[Test]
    public function the_ordinary_grid_stays_for_a_term_a_filter_a_stranger_and_too_thin_a_match(): void
    {
        $user = User::factory()->create();
        $this->saved($user, $this->group('Sony', 'audio'));

        foreach (range(1, 4) as $i) {
            $this->group('Sony', 'audio');
        }

        // A term or a filter is a question of its own.
        $this->actingAs($user)->get('/be-nl/search?q=lego')
            ->assertInertia(fn ($page) => $page->where('seeded', null));
        $this->actingAs($user)->get('/be-nl/search?min=10')
            ->assertInertia(fn ($page) => $page->where('seeded', null));

        // Nothing saved, nothing to seed from.
        auth()->logout();
        $this->get('/be-nl/search')
            ->assertInertia(fn ($page) => $page->where('seeded', null));

        // Fewer similar products than a row is worth: the ordinary grid.
        $thin = User::factory()->create();
        $this->saved($thin, $this->group('Nobody', 'nothing'));
        $this->actingAs($thin)->get('/be-nl/search')
            ->assertInertia(fn ($page) => $page->where('seeded', null));
    }
}
