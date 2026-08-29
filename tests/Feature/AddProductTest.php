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
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Putting a thing on a list, from the list.
 *
 * This used to be two buttons — one that navigated away to a search, and one
 * that opened a form for the case where the catalogue does not have it. That
 * split asked somebody to know, before typing anything, whether we happen to
 * stock what they are thinking of, which is a question only we can answer.
 */
class AddProductTest extends TestCase
{
    use RefreshDatabase;

    private function user(): User
    {
        return User::factory()->create();
    }

    private function listFor(User $user): Wishlist
    {
        return Wishlist::factory()->create([
            'owner_user_id' => $user->id,
            'kind' => ListKind::Mine,
            'title' => 'Camping',
            'market' => Market::BeNl,
        ]);
    }

    #[Test]
    public function the_panel_searches_the_catalogue(): void
    {
        $match = ProductGroup::factory()->create([
            'market' => Market::BeNl,
            'title' => 'Draadloze koptelefoon met ruisonderdrukking',
        ]);

        ProductGroup::factory()->create(['market' => Market::BeNl, 'title' => 'Tuinslang 20 meter']);

        $body = $this->actingAs($this->user())
            ->getJson('/be-nl/list-search?q=koptelefoon')
            ->assertOk()
            ->assertJsonStructure(['groups', 'live'])
            ->json();

        $this->assertSame([$match->id], array_column($body['groups'], 'id'));
    }

    /**
     * Below two characters every term matches half the catalogue, and every
     * live connector is asked a question with no answer — at the cost of a
     * request each time.
     */
    #[Test]
    public function a_one_letter_term_asks_nobody_anything(): void
    {
        ProductGroup::factory()->create(['market' => Market::BeNl, 'title' => 'Koptelefoon']);

        $this->actingAs($this->user())
            ->getJson('/be-nl/list-search?q=k')
            ->assertOk()
            ->assertExactJson(['groups' => [], 'live' => []]);
    }

    /**
     * Searching from inside a list is not public demand.
     *
     * `search_log` feeds the related-search chips on public pages and the queue
     * that decides which buying guides get written, and a term typed while
     * filling a gift list is about one named person.
     */
    #[Test]
    public function the_term_does_not_reach_the_public_search_log(): void
    {
        ProductGroup::factory()->create(['market' => Market::BeNl, 'title' => 'Verlovingsring goud']);

        $this->actingAs($this->user())
            ->getJson('/be-nl/list-search?q=verlovingsring')
            ->assertOk();

        $this->assertSame(0, (int) DB::table('search_log')->count());
    }

    #[Test]
    public function a_guest_cannot_search_from_a_list(): void
    {
        $this->get('/be-nl/list-search?q=koptelefoon')->assertRedirect('/be-nl/login');
    }

    /**
     * The wording is the owner's.
     *
     * A feed title is written for a search engine; a list is read by a person,
     * sometimes one choosing a present under time pressure.
     */
    #[Test]
    public function the_description_can_be_adjusted_while_adding(): void
    {
        $user = $this->user();
        $list = $this->listFor($user);

        $group = ProductGroup::factory()->create([
            'market' => Market::BeNl,
            'title' => 'Merk XY-3000 draadloze koptelefoon met ANC, zwart, 2024',
        ]);

        $this->actingAs($user)->post('/be-nl/list-items', [
            'wishlist_id' => $list->id,
            'group_id' => $group->id,
            'title' => 'De koptelefoon die ik wil',
            'note' => 'in het zwart',
        ])->assertRedirect();

        $item = WishlistItem::query()->where('group_id', $group->id)->firstOrFail();

        $this->assertSame('De koptelefoon die ik wil', $item->snapshot_title);
        $this->assertSame('in het zwart', $item->note);

        // Only the words change: the product it points at, and therefore its
        // price and its offer comparison, are untouched.
        $this->assertSame($group->id, $item->group_id);
    }

    /**
     * Saving the same product again from a product card posts neither a title
     * nor a note. Before this, that quietly reset both.
     */
    #[Test]
    public function saving_it_again_does_not_undo_the_wording(): void
    {
        $user = $this->user();
        $list = $this->listFor($user);
        $group = ProductGroup::factory()->create(['market' => Market::BeNl]);

        $this->actingAs($user)->post('/be-nl/list-items', [
            'wishlist_id' => $list->id,
            'group_id' => $group->id,
            'title' => 'Mijn eigen omschrijving',
            'note' => 'maat M',
        ])->assertRedirect();

        // The bookmark on a card, which knows about neither field.
        $this->actingAs($user)->postJson('/be-nl/list-items', [
            'wishlist_id' => $list->id,
            'group_id' => $group->id,
        ])->assertOk();

        $item = WishlistItem::query()->where('group_id', $group->id)->firstOrFail();

        $this->assertSame('Mijn eigen omschrijving', $item->snapshot_title);
        $this->assertSame('maat M', $item->note);
    }

    #[Test]
    public function a_product_added_without_a_title_keeps_the_catalogue_wording(): void
    {
        $user = $this->user();
        $list = $this->listFor($user);
        $group = ProductGroup::factory()->create(['market' => Market::BeNl, 'title' => 'Tent voor twee']);

        $this->actingAs($user)->post('/be-nl/list-items', [
            'wishlist_id' => $list->id,
            'group_id' => $group->id,
        ])->assertRedirect();

        $this->assertSame(
            'Tent voor twee',
            WishlistItem::query()->where('group_id', $group->id)->firstOrFail()->snapshot_title,
        );
    }

    /**
     * The whole reason the hand-written path exists: a voucher for the climbing
     * gym is in nobody's catalogue, and it is reachable without searching first.
     */
    #[Test]
    public function something_we_do_not_sell_can_be_added_without_searching(): void
    {
        $user = $this->user();
        $list = $this->listFor($user);

        $this->actingAs($user)->post('/be-nl/list-items', [
            'wishlist_id' => $list->id,
            'source' => 'manual',
            'title' => 'Bon voor de klimzaal',
            'url' => 'https://klimzaal.example/bon',
            'price' => 4500,
            'note' => 'liefst een 10-beurtenkaart',
        ])->assertRedirect();

        $item = $list->items()->firstOrFail();

        $this->assertSame('Bon voor de klimzaal', $item->snapshot_title);
        $this->assertSame('https://klimzaal.example/bon', $item->snapshot_url);
        $this->assertSame(4500, $item->snapshot_price);
        $this->assertSame('liefst een 10-beurtenkaart', $item->note);
        $this->assertNull($item->group_id);
    }

    /** A hand-written link is hostile input until proven otherwise. */
    #[Test]
    public function a_javascript_link_is_refused(): void
    {
        $user = $this->user();
        $list = $this->listFor($user);

        $this->actingAs($user)->post('/be-nl/list-items', [
            'wishlist_id' => $list->id,
            'source' => 'manual',
            'title' => 'Iets',
            'url' => 'javascript:alert(1)',
        ])->assertSessionHasErrors('url');

        $this->assertSame(0, $list->items()->count());
    }
}
