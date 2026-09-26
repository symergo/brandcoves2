<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ListKind;
use App\Enums\ListVisibility;
use App\Enums\Market;
use App\Enums\RecipientStatus;
use App\Models\ListOpen;
use App\Models\ProductGroup;
use App\Models\Recipient;
use App\Models\User;
use App\Models\Wishlist;
use App\Models\WishlistItem;
use App\Services\Social\Friends;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * "Visible to my people": a wish list your friends on GiftCoves can see and pick from.
 *
 * The owner's request of 2026-09-26. What has to hold: new wish lists have it
 * on and old ones keep what they were; only a mutual friend sees the list, and
 * only while the option is on and the friendship stands; a friend making a
 * list about the owner can pick from it, taking the product but not the
 * owner's note; claims made that way never reach the owner (invariant 4); and
 * none of it depends on the market the list was made in.
 * See docs/features/wish-list-for-my-people.md.
 */
class WishListForMyPeopleTest extends TestCase
{
    use RefreshDatabase;

    private User $anna;

    private User $ben;

    protected function setUp(): void
    {
        parent::setUp();

        $this->anna = User::factory()->create(['name' => 'Anna']);
        $this->ben = User::factory()->create(['name' => 'Ben']);
    }

    #[Test]
    public function a_new_wish_list_is_visible_to_your_people_and_a_gift_list_is_not(): void
    {
        $this->actingAs($this->anna)->post('/be-nl/lists', ['title' => 'Mine'])->assertRedirect();
        $this->actingAs($this->anna)->post('/be-nl/lists', ['new_recipient' => 'Sara'])->assertRedirect();

        $mine = Wishlist::query()->where('owner_user_id', $this->anna->id)->where('kind', ListKind::Mine->value)->sole();
        $gift = Wishlist::query()->where('owner_user_id', $this->anna->id)->where('kind', ListKind::ForSomeone->value)->sole();

        $this->assertTrue($mine->visible_to_friends);
        // No link: visible to her people, private to everybody else.
        $this->assertSame(ListVisibility::Private, $mine->visibility);
        $this->assertFalse($gift->visible_to_friends);

        $this->actingAs($this->anna)->get("/be-nl/lists/{$mine->id}")
            ->assertInertia(fn ($page) => $page->where('list.visibleToFriends', true));
        $this->actingAs($this->anna)->get("/be-nl/lists/{$gift->id}")
            ->assertInertia(fn ($page) => $page->where('list.visibleToFriends', null));
    }

    #[Test]
    public function the_default_wish_list_is_visible_when_made_and_an_adopted_one_keeps_its_setting(): void
    {
        // Made on the first save.
        $this->actingAs($this->anna)->post('/be-nl/list-items', ['group_id' => $this->product()->id]);

        $this->assertTrue(
            Wishlist::query()->where('owner_user_id', $this->anna->id)->where('is_default', true)->sole()->visible_to_friends,
        );

        // A list Ben made before the option existed is adopted as it is.
        $old = Wishlist::factory()->create(['owner_user_id' => $this->ben->id, 'kind' => ListKind::Mine]);

        $this->actingAs($this->ben)->post('/be-nl/list-items', ['group_id' => $this->product()->id]);

        $this->assertTrue($old->fresh()->is_default);
        $this->assertFalse($old->fresh()->visible_to_friends);
    }

    #[Test]
    public function the_owner_switches_it_off_and_on_and_only_on_a_wish_list(): void
    {
        $list = $this->wishList();

        $this->actingAs($this->anna)->patch("/be-nl/lists/{$list->id}", ['visible_to_friends' => false])->assertRedirect();
        $this->assertFalse($list->fresh()->visible_to_friends);

        $this->actingAs($this->anna)->patch("/be-nl/lists/{$list->id}", ['visible_to_friends' => true])->assertRedirect();
        $this->assertTrue($list->fresh()->visible_to_friends);

        // A list about somebody else is research about a third person.
        $gift = Wishlist::factory()->forSomeone()->create(['owner_user_id' => $this->anna->id]);
        $this->actingAs($this->anna)->patch("/be-nl/lists/{$gift->id}", ['visible_to_friends' => true]);
        $this->assertFalse($gift->fresh()->visible_to_friends);

        // Nobody else can switch it.
        $this->actingAs($this->ben)->patch("/be-nl/lists/{$list->id}", ['visible_to_friends' => false])->assertNotFound();
        $this->assertTrue($list->fresh()->visible_to_friends);
    }

    #[Test]
    public function a_friend_opens_it_and_nobody_else_does(): void
    {
        $list = $this->wishList();
        app(Friends::class)->link($this->anna, $this->ben);

        $this->actingAs($this->ben)->get("/be-nl/l/{$list->share_token}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Lists/Shared'));

        // Not a bookmark: it would outlive the owner switching it off.
        $this->assertFalse(ListOpen::query()->where('wishlist_id', $list->id)->exists());

        // A stranger with the link, and a visitor without an account.
        $stranger = User::factory()->create();
        $this->actingAs($stranger)->get("/be-nl/l/{$list->share_token}")->assertNotFound();
        auth()->logout();
        $this->get("/be-nl/l/{$list->share_token}")->assertNotFound();
    }

    #[Test]
    public function somebody_who_only_saved_her_never_sees_it(): void
    {
        $list = $this->wishList();

        // Carl saved Anna as a person he buys for, linked to her account, and
        // is not her friend.
        $carl = User::factory()->create();
        $recipient = Recipient::factory()->create([
            'owner_user_id' => $carl->id,
            'user_id' => $this->anna->id,
            'status' => RecipientStatus::Linked,
            'name' => 'Anna',
        ]);
        $about = Wishlist::factory()->forSomeone($recipient)->create(['owner_user_id' => $carl->id]);

        $this->actingAs($carl)->get("/be-nl/l/{$list->share_token}")->assertNotFound();
        $this->actingAs($carl)->get("/be-nl/lists/{$about->id}")
            ->assertInertia(fn ($page) => $page->has('asked', 0));
    }

    #[Test]
    public function switching_it_off_or_ending_the_friendship_takes_it_away_at_once(): void
    {
        $list = $this->wishList();
        app(Friends::class)->link($this->anna, $this->ben);

        $this->actingAs($this->ben)->get("/be-nl/l/{$list->share_token}")->assertOk();

        $list->update(['visible_to_friends' => false]);
        $this->actingAs($this->ben)->get("/be-nl/l/{$list->share_token}")->assertNotFound();
        $this->actingAs($this->ben)->get('/be-nl/people')
            ->assertInertia(fn ($page) => $page->has('people.0.friend.lists', 0));

        $list->update(['visible_to_friends' => true]);
        $this->actingAs($this->ben)->get("/be-nl/l/{$list->share_token}")->assertOk();

        app(Friends::class)->unlink($this->anna, $this->ben->id);
        $this->actingAs($this->ben)->get("/be-nl/l/{$list->share_token}")->assertNotFound();
    }

    #[Test]
    public function it_is_on_the_friends_row_on_my_people_and_on_the_owners_side_too(): void
    {
        $list = $this->wishList();
        app(Friends::class)->link($this->anna, $this->ben);

        $this->actingAs($this->ben)->get('/be-nl/people')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('people.0.name', 'Anna')
                ->has('people.0.friend.lists', 1)
                ->where('people.0.friend.lists.0.url', fn ($url) => str_ends_with($url, "/be-nl/l/{$list->share_token}")));

        // What Anna's friends see of hers, on her own page.
        $this->actingAs($this->anna)->get('/be-nl/people')
            ->assertInertia(fn ($page) => $page->has('people.0.friend.theySee', 1));
    }

    #[Test]
    public function a_friend_picks_from_it_for_a_list_about_her_without_her_note(): void
    {
        $list = $this->wishList();
        $group = $this->product();
        $wish = WishlistItem::factory()->of($group)->create([
            'wishlist_id' => $list->id,
            'note' => 'Size M, the blue one',
        ]);
        app(Friends::class)->link($this->anna, $this->ben);

        // Ben makes a list for his friend Anna: the recipient is linked to her.
        $this->actingAs($this->ben)->post('/be-nl/lists', ['friend_id' => $this->anna->id])->assertRedirect();
        $gift = Wishlist::query()->where('owner_user_id', $this->ben->id)->sole();

        $this->actingAs($this->ben)->get("/be-nl/lists/{$gift->id}")
            ->assertInertia(fn ($page) => $page
                ->where('target.isLinked', true)
                ->has('asked', 1)
                ->where('asked.0.id', $wish->id)
                ->where('asked.0.groupId', $group->id)
                ->where('asked.0.token', $list->share_token));

        $this->actingAs($this->ben)
            ->post("/be-nl/l/{$list->share_token}/items/{$wish->id}/copy", ['to' => $gift->id])
            ->assertRedirect();

        $copy = WishlistItem::query()->where('wishlist_id', $gift->id)->sole();
        $this->assertSame($group->id, $copy->group_id);
        $this->assertNull($copy->note);
        $this->assertNull($copy->claimed_by_hash);

        // Her list is untouched.
        $this->assertSame('Size M, the blue one', $wish->fresh()->note);
        $this->assertSame(1, WishlistItem::query()->where('wishlist_id', $list->id)->count());
    }

    #[Test]
    public function a_stranger_cannot_copy_from_it(): void
    {
        $list = $this->wishList();
        $wish = WishlistItem::factory()->of($this->product())->create(['wishlist_id' => $list->id]);

        $stranger = User::factory()->create();
        $theirs = Wishlist::factory()->create(['owner_user_id' => $stranger->id]);

        $this->actingAs($stranger)
            ->post("/be-nl/l/{$list->share_token}/items/{$wish->id}/copy", ['to' => $theirs->id])
            ->assertNotFound();

        $this->assertSame(0, WishlistItem::query()->where('wishlist_id', $theirs->id)->count());
    }

    #[Test]
    public function a_friends_claim_never_reaches_the_owner(): void
    {
        $list = $this->wishList();
        $wish = WishlistItem::factory()->of($this->product())->create(['wishlist_id' => $list->id]);
        app(Friends::class)->link($this->anna, $this->ben);

        // A private list with only her people on it still has someone to
        // coordinate with, so claiming is allowed.
        $this->assertTrue($list->fresh()->allowsClaiming());

        $this->actingAs($this->ben)
            ->post("/be-nl/l/{$list->share_token}/claim/{$wish->id}")
            ->assertRedirect();

        $this->assertNotNull($wish->fresh()->claimed_by_hash);

        // Anna's own page carries no claim state at all.
        $response = $this->actingAs($this->anna)->get("/be-nl/lists/{$list->id}")->assertOk();
        $items = $response->viewData('page')['props']['items'];
        $this->assertCount(1, $items);
        $this->assertArrayNotHasKey('claimed', $items[0]);
        $this->assertArrayNotHasKey('claimedByMe', $items[0]);
        $response->assertInertia(fn ($page) => $page->where('board', null));
    }

    #[Test]
    public function it_does_not_depend_on_the_market_the_list_was_made_in(): void
    {
        $list = $this->wishList(['market' => Market::NlNl]);
        $wish = WishlistItem::factory()->of($this->product(Market::NlNl))->create(['wishlist_id' => $list->id]);
        app(Friends::class)->link($this->anna, $this->ben);

        $this->actingAs($this->ben)->get("/en/l/{$list->share_token}")->assertOk();

        $this->actingAs($this->ben)->post('/be-nl/lists', ['friend_id' => $this->anna->id]);
        $gift = Wishlist::query()->where('owner_user_id', $this->ben->id)->sole();

        $this->actingAs($this->ben)->get("/es/lists/{$gift->id}")
            ->assertInertia(fn ($page) => $page->has('asked', 1));

        $this->actingAs($this->ben)
            ->post("/es/l/{$list->share_token}/items/{$wish->id}/copy", ['to' => $gift->id])
            ->assertRedirect();

        $this->assertSame(1, WishlistItem::query()->where('wishlist_id', $gift->id)->count());
    }

    #[Test]
    public function a_link_shared_wish_list_with_the_option_off_reaches_a_friend_only_when_given_to_them(): void
    {
        // Shared by link, never sent to Ben, option off.
        $list = $this->wishList(['visibility' => ListVisibility::Link, 'visible_to_friends' => false]);
        WishlistItem::factory()->of($this->product())->create(['wishlist_id' => $list->id]);
        app(Friends::class)->link($this->anna, $this->ben);

        $this->actingAs($this->ben)->post('/be-nl/lists', ['friend_id' => $this->anna->id]);
        $gift = Wishlist::query()->where('owner_user_id', $this->ben->id)->sole();

        $this->actingAs($this->ben)->get("/be-nl/lists/{$gift->id}")
            ->assertInertia(fn ($page) => $page->has('asked', 0));

        // Once he has opened the link she sent him, it is his to pick from.
        $this->actingAs($this->ben)->get("/be-nl/l/{$list->share_token}")->assertOk();

        $this->actingAs($this->ben)->get("/be-nl/lists/{$gift->id}")
            ->assertInertia(fn ($page) => $page->has('asked', 1));
    }

    /** @param  array<string, mixed>  $attributes */
    private function wishList(array $attributes = []): Wishlist
    {
        return Wishlist::factory()->create([
            'owner_user_id' => $this->anna->id,
            'kind' => ListKind::Mine,
            'visibility' => ListVisibility::Private,
            'visible_to_friends' => true,
            ...$attributes,
        ]);
    }

    private function product(Market $market = Market::BeNl): ProductGroup
    {
        return ProductGroup::factory()->create(['market' => $market]);
    }
}
