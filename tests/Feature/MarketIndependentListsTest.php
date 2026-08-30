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
 * A wish list belongs to a person, not to a market.
 *
 * Everything else in this application is scoped to a market and rightly so: a
 * product, a price, an offer and a search result are all statements about one
 * country's shops, and invariant #2 keeps them apart. A list is not one of
 * those. It is what somebody wants, and somebody who lives on the Dutch border
 * or reads English by preference switches market several times in an afternoon
 * without ever meaning to start a second collection.
 *
 * `wishlists.market` survives as *provenance* — which market the list was made
 * in, which is what freezes the language of a default title — and is never a
 * filter. These are the four places it was one.
 */
class MarketIndependentListsTest extends TestCase
{
    use RefreshDatabase;

    private function group(Market $market): ProductGroup
    {
        return ProductGroup::create([
            'market' => $market,
            'identity_key' => 'k'.bin2hex(random_bytes(4)),
            'identity_kind' => 'ean',
            'title' => 'Sony WH-1000XM5',
            'slug' => 'sony-wh-1000xm5',
            'image_url' => 'https://img.test/1.jpg',
            'min_price' => 32999,
            'merchant_count' => 2,
            'in_stock' => true,
        ]);
    }

    #[Test]
    public function the_save_picker_offers_a_list_made_in_another_market(): void
    {
        $user = User::factory()->create();

        Wishlist::factory()->create([
            'owner_user_id' => $user->id,
            'kind' => ListKind::Mine,
            'title' => 'Things I want',
            'market' => Market::NlNl,
        ]);

        /*
         * The list is the one they already have. Filtered by market, this
         * answered with an empty picker and an invitation to start a second
         * one — on a page that exists to put a product somewhere.
         */
        $this->actingAs($user)
            ->getJson('/be-nl/list-options')
            ->assertOk()
            ->assertJsonCount(1, 'lists')
            ->assertJsonPath('lists.0.title', 'Things I want');
    }

    #[Test]
    public function the_saved_badge_reports_a_product_held_on_a_list_from_another_market(): void
    {
        $user = User::factory()->create();
        $group = $this->group(Market::BeNl);

        $list = Wishlist::factory()->create([
            'owner_user_id' => $user->id,
            'kind' => ListKind::Mine,
            'market' => Market::NlNl,
        ]);

        WishlistItem::factory()->of($group)->create(['wishlist_id' => $list->id]);

        /*
         * The product is a `be-nl` one and the reader is on `be-nl`; only the
         * list was made elsewhere. The badge asks about the *group's* market,
         * so this is the ordinary case rather than a cross-market one — and it
         * is exactly what the old filter got wrong.
         */
        $this->actingAs($user)
            ->getJson('/be-nl/saved-items')
            ->assertJsonPath('groupIds', [$group->id]);
    }

    #[Test]
    public function an_item_links_to_its_own_market_not_the_readers(): void
    {
        $user = User::factory()->create();
        $group = $this->group(Market::NlNl);

        $list = Wishlist::factory()->create([
            'owner_user_id' => $user->id,
            'kind' => ListKind::Mine,
            'market' => Market::BeNl,
        ]);

        WishlistItem::factory()->of($group)->create(['wishlist_id' => $list->id]);

        /*
         * Read on `be-nl`, linking into `nl-nl`. Prefixed with the reader's
         * market this was `/be-nl/p/{an nl-nl group id}/…`, and `product_groups`
         * is unique on `(market, identity_key)` — so the row is not in that
         * market and the link was a 404 on the visitor's own saved product.
         */
        $this->actingAs($user)
            ->get("/be-nl/lists/{$list->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('items.0.url', "/nl-nl/p/{$group->id}/sony-wh-1000xm5"));
    }

    #[Test]
    public function switching_market_does_not_hand_somebody_a_second_default_list(): void
    {
        $user = User::factory()->create();

        $existing = Wishlist::factory()->create([
            'owner_user_id' => $user->id,
            'kind' => ListKind::Mine,
            'is_default' => false,
            'market' => Market::NlNl,
        ]);

        /*
         * `DefaultList` adopts the oldest `mine` list before it makes one. That
         * lookup was market-scoped, so the first save after a switch created a
         * second list — both called "My wishlist", both default, and which one
         * a save landed on decided by the prefix in the URL at the time.
         */
        $this->actingAs($user)
            ->post('/be-nl/list-items', ['group_id' => $this->group(Market::BeNl)->id]);

        $this->assertSame(
            1,
            Wishlist::query()->where('owner_user_id', $user->id)->where('is_default', true)->count(),
        );

        $this->assertTrue($existing->fresh()->is_default);
    }
}
