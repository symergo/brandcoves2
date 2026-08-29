<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ListKind;
use App\Enums\Market;
use App\Models\ProductGroup;
use App\Models\Recipient;
use App\Models\User;
use App\Models\Wishlist;
use App\Models\WishlistItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Saving a product somewhere you chose.
 *
 * Every save used to land in a default `mine` list with no way to pick, so
 * "keep this for my sister" had no path through the interface at all — the
 * schema could express it and nothing could reach it.
 */
class SaveToListTest extends TestCase
{
    use RefreshDatabase;

    private function group(): ProductGroup
    {
        return ProductGroup::factory()->create(['market' => Market::BeNl]);
    }

    #[Test]
    public function the_picker_offers_both_kinds_of_list(): void
    {
        $user = User::factory()->create();

        Wishlist::factory()->create([
            'owner_user_id' => $user->id,
            'kind' => ListKind::Mine,
            'title' => 'Things I want',
            'market' => Market::BeNl,
        ]);

        $recipient = Recipient::factory()->create(['owner_user_id' => $user->id, 'name' => 'Mum']);

        Wishlist::factory()->create([
            'owner_user_id' => $user->id,
            'recipient_id' => $recipient->id,
            'kind' => ListKind::ForSomeone,
            'market' => Market::BeNl,
        ]);

        $lists = $this->actingAs($user)
            ->getJson('/be-nl/list-options')
            ->assertOk()
            ->assertJsonCount(2, 'lists')
            ->json('lists');

        // By content, not position: both rows are written in the same second, so
        // ordering on `updated_at` is a coin toss and asserting an index would
        // be a test that fails for a reason unrelated to what it checks.
        $byKind = array_column($lists, null, 'kind');

        $this->assertArrayHasKey('mine', $byKind);
        $this->assertArrayHasKey('for_someone', $byKind);
        $this->assertSame('Things I want', $byKind['mine']['title']);
        $this->assertSame('Mum', $byKind['for_someone']['recipient']);
    }

    #[Test]
    public function the_picker_is_empty_rather_than_forbidden_for_a_stranger(): void
    {
        // Anonymous-first: no identity yet is a normal state, not an error.
        $this->getJson('/be-nl/list-options')
            ->assertOk()
            ->assertJsonPath('lists', [])
            ->assertJsonPath('recipients', []);
    }

    #[Test]
    public function a_product_can_be_saved_into_a_chosen_list(): void
    {
        $user = User::factory()->create();

        $mine = Wishlist::factory()->create([
            'owner_user_id' => $user->id,
            'kind' => ListKind::Mine,
            'market' => Market::BeNl,
        ]);

        $forMum = Wishlist::factory()->create([
            'owner_user_id' => $user->id,
            'recipient_id' => Recipient::factory()->create(['owner_user_id' => $user->id])->id,
            'kind' => ListKind::ForSomeone,
            'market' => Market::BeNl,
        ]);

        $this->actingAs($user)
            ->post('/be-nl/list-items', [
                'group_id' => $this->group()->id,
                'wishlist_id' => $forMum->id,
            ])
            ->assertRedirect();

        $this->assertSame(1, $forMum->items()->count());
        $this->assertSame(0, $mine->items()->count());
    }

    #[Test]
    public function a_new_list_can_be_created_in_the_same_step(): void
    {
        $user = User::factory()->create();

        /*
         * "Save this to a new list for my sister" is one intention. Making
         * somebody leave the product, create a list, come back and find the
         * product again is how the second list never gets made.
         */
        $this->actingAs($user)
            ->post('/be-nl/list-items', [
                'group_id' => $this->group()->id,
                'new_list' => 'For Kim',
                'new_recipient' => 'Kim',
            ])
            ->assertRedirect();

        $list = Wishlist::query()->where('title', 'For Kim')->firstOrFail();

        // The recipient decides the kind; the two cannot disagree.
        $this->assertSame(ListKind::ForSomeone, $list->kind);
        $this->assertSame('Kim', $list->recipient->name);
        $this->assertSame(1, $list->items()->count());
    }

    #[Test]
    public function a_new_list_without_a_person_is_my_own(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post('/be-nl/list-items', [
                'group_id' => $this->group()->id,
                'new_list' => 'Birthday ideas',
            ])
            ->assertRedirect();

        $list = Wishlist::query()->where('title', 'Birthday ideas')->firstOrFail();

        $this->assertSame(ListKind::Mine, $list->kind);
        $this->assertNull($list->recipient_id);
    }

    #[Test]
    public function the_picker_says_which_lists_already_hold_the_product(): void
    {
        $user = User::factory()->create();
        $group = $this->group();

        $holding = Wishlist::factory()->create([
            'owner_user_id' => $user->id,
            'kind' => ListKind::Mine,
            'title' => 'Has it',
            'market' => Market::BeNl,
        ]);

        $empty = Wishlist::factory()->create([
            'owner_user_id' => $user->id,
            'kind' => ListKind::Mine,
            'title' => 'Does not',
            'market' => Market::BeNl,
        ]);

        $this->actingAs($user)->post('/be-nl/list-items', [
            'group_id' => $group->id,
            'wishlist_id' => $holding->id,
        ]);

        /*
         * Without this the picker is a one-way door: every row looks the same,
         * so a save into the wrong list can only be undone by going and finding
         * that list. The item id is what lets the row that put it there take it
         * off again, through the existing `destroy()` and its ownership check.
         */
        $rows = array_column(
            $this->actingAs($user)
                ->getJson("/be-nl/list-options?group_id={$group->id}")
                ->assertOk()
                ->json('lists'),
            null,
            'title',
        );

        $this->assertNotNull($rows['Has it']['itemId']);
        $this->assertNull($rows['Does not']['itemId']);
        $this->assertSame(
            $holding->items()->firstOrFail()->id,
            $rows['Has it']['itemId'],
        );
    }

    #[Test]
    public function asking_without_a_product_reports_no_membership(): void
    {
        $user = User::factory()->create();

        Wishlist::factory()->create([
            'owner_user_id' => $user->id,
            'kind' => ListKind::Mine,
            'market' => Market::BeNl,
        ]);

        // The picker is also opened from surfaces with no stored group at all —
        // a live bol result, an Amazon product — and must not claim membership
        // it has not been asked about.
        $this->actingAs($user)
            ->getJson('/be-nl/list-options')
            ->assertOk()
            ->assertJsonPath('lists.0.itemId', null);
    }

    #[Test]
    public function saving_says_which_list_it_went_into(): void
    {
        $user = User::factory()->create();

        $list = Wishlist::factory()->create([
            'owner_user_id' => $user->id,
            'kind' => ListKind::Mine,
            'title' => 'Camping',
            'market' => Market::BeNl,
        ]);

        // "Saved to your list" is true of every save and so answers nothing —
        // a save can land in the default list, one picked from the menu, or one
        // created in the same click.
        $this->actingAs($user)
            ->post('/be-nl/list-items', ['group_id' => $this->group()->id, 'wishlist_id' => $list->id])
            ->assertSessionHas('success', fn (string $flash) => str_contains($flash, 'Camping'));
    }

    #[Test]
    public function you_cannot_attach_someone_elses_person_to_your_list(): void
    {
        $stranger = Recipient::factory()->create([
            'owner_user_id' => User::factory()->create()->id,
        ]);

        $this->actingAs(User::factory()->create())
            ->post('/be-nl/list-items', [
                'group_id' => $this->group()->id,
                'new_list' => 'Sneaky',
                'recipient_id' => $stranger->id,
            ])
            ->assertForbidden();
    }

    #[Test]
    public function a_list_for_a_new_person_can_be_made_from_the_lists_page(): void
    {
        $user = User::factory()->create();

        /*
         * The recipient dropdown on that form is drawn from people you already
         * have, and the only place to mint one was the picker on a product
         * card — so "a list for my sister" was reachable from a search result
         * and not from the page called My lists.
         */
        $this->actingAs($user)
            ->post('/be-nl/lists', ['title' => 'For Robin', 'new_recipient' => 'Robin'])
            ->assertRedirect();

        $list = Wishlist::query()->where('title', 'For Robin')->firstOrFail();

        $this->assertSame(ListKind::ForSomeone, $list->kind);
        $this->assertSame('Robin', $list->recipient->name);
    }

    #[Test]
    public function you_cannot_save_into_a_list_you_have_no_part_in(): void
    {
        $other = Wishlist::factory()->create([
            'owner_user_id' => User::factory()->create()->id,
            'kind' => ListKind::Mine,
            'market' => Market::BeNl,
        ]);

        $this->actingAs(User::factory()->create())
            ->post('/be-nl/list-items', [
                'group_id' => $this->group()->id,
                'wishlist_id' => $other->id,
            ])
            ->assertNotFound();
    }

    /**
     * The save control is an XHR now, and it needs the row back.
     *
     * `back()` cost a full page rebuild to move one row and delivered the
     * confirmation through `flash`, which renders at the top of the document —
     * unreadable from the bottom of a results grid, which is where saving
     * happens. It also cannot carry an item id, so there was nothing an Undo
     * could act on.
     */
    #[Test]
    public function a_json_save_answers_with_the_row_and_the_list(): void
    {
        $user = User::factory()->create();
        $group = $this->group();

        $list = Wishlist::factory()->create([
            'owner_user_id' => $user->id,
            'kind' => ListKind::Mine,
            'title' => 'Camping',
            'market' => Market::BeNl,
        ]);

        $body = $this->actingAs($user)
            ->postJson('/be-nl/list-items', ['group_id' => $group->id, 'wishlist_id' => $list->id])
            ->assertOk()
            ->json();

        $item = WishlistItem::query()->where('group_id', $group->id)->firstOrFail();

        $this->assertSame($item->id, $body['itemId']);
        $this->assertSame($list->id, $body['listId']);
        $this->assertSame('Camping', $body['listTitle']);

        // By content, not by wording: this market reads Dutch, and the
        // invariant is that the confirmation names its destination.
        $this->assertStringContainsString('Camping', $body['message']);
    }

    /**
     * And the form path is untouched.
     *
     * `ManualItem` and the list pages submit through Inertia and do want the
     * page back, so the two shapes have to coexist rather than replace one
     * another.
     */
    #[Test]
    public function a_form_save_still_redirects_back_with_the_flash(): void
    {
        $this->actingAs(User::factory()->create())
            ->post('/be-nl/list-items', ['group_id' => $this->group()->id])
            ->assertRedirect()
            ->assertSessionHas('success');
    }

    #[Test]
    public function removing_answers_json_too_so_undo_can_use_it(): void
    {
        $user = User::factory()->create();
        $group = $this->group();

        $itemId = $this->actingAs($user)
            ->postJson('/be-nl/list-items', ['group_id' => $group->id])
            ->json('itemId');

        $this->actingAs($user)
            ->deleteJson("/be-nl/list-items/{$itemId}")
            ->assertOk()
            ->assertJsonStructure(['message']);

        $this->assertDatabaseMissing('wishlist_items', ['id' => $itemId]);
    }

    /**
     * `?list=` answers "is this one on Camping yet?".
     *
     * While a named list is being filled, "it is on one of your lists
     * somewhere" is the wrong question: it ticks items that are on a different
     * list and hides the ones actually added during this run.
     */
    #[Test]
    public function saved_items_can_answer_for_one_list(): void
    {
        $user = User::factory()->create();
        $onBoth = $this->group();
        $elsewhere = $this->group();

        $camping = Wishlist::factory()->create([
            'owner_user_id' => $user->id,
            'kind' => ListKind::Mine,
            'title' => 'Camping',
            'market' => Market::BeNl,
        ]);

        $books = Wishlist::factory()->create([
            'owner_user_id' => $user->id,
            'kind' => ListKind::Mine,
            'title' => 'Books',
            'market' => Market::BeNl,
        ]);

        $this->actingAs($user)->postJson('/be-nl/list-items', [
            'group_id' => $onBoth->id,
            'wishlist_id' => $camping->id,
        ])->assertOk();

        /*
         * Named explicitly rather than left to the default. `DefaultList`
         * *adopts* an existing `mine` list rather than making a second one, so
         * an unqualified save here would have landed on Camping too and the
         * assertion would have passed for the wrong reason.
         */
        $this->actingAs($user)->postJson('/be-nl/list-items', [
            'group_id' => $elsewhere->id,
            'wishlist_id' => $books->id,
        ])->assertOk();

        $body = $this->actingAs($user)
            ->getJson("/be-nl/saved-items?list={$camping->id}")
            ->assertOk()
            ->json();

        $this->assertEqualsCanonicalizing([$onBoth->id, $elsewhere->id], $body['groupIds']);
        $this->assertSame([$onBoth->id], $body['listGroupIds']);
    }

    /**
     * Asking about a list you have no part in is a read of somebody's list
     * membership, and is gated like one — empty, rather than its contents.
     */
    #[Test]
    public function saved_items_will_not_report_on_a_stranger_list(): void
    {
        $stranger = User::factory()->create();
        $group = $this->group();

        $theirs = Wishlist::factory()->create([
            'owner_user_id' => $stranger->id,
            'kind' => ListKind::Mine,
            'market' => Market::BeNl,
        ]);

        $this->actingAs($stranger)->postJson('/be-nl/list-items', [
            'group_id' => $group->id,
            'wishlist_id' => $theirs->id,
        ])->assertOk();

        $this->actingAs(User::factory()->create())
            ->getJson("/be-nl/saved-items?list={$theirs->id}")
            ->assertOk()
            ->assertExactJson(['groupIds' => [], 'listGroupIds' => []]);
    }
}
