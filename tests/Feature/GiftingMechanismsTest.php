<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ListKind;
use App\Enums\ListVisibility;
use App\Enums\Market;
use App\Enums\RecipientStatus;
use App\Models\GiftPledge;
use App\Models\LoginToken;
use App\Models\ProductGroup;
use App\Models\Recipient;
use App\Models\User;
use App\Models\Wishlist;
use App\Models\WishlistCollaborator;
use App\Models\WishlistItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The standard wishlist, handovers, group gifts, registries and suggestions.
 */
class GiftingMechanismsTest extends TestCase
{
    use RefreshDatabase;

    private function sharedList(User $owner): Wishlist
    {
        return Wishlist::factory()->create([
            'owner_user_id' => $owner->id,
            'kind' => ListKind::Mine,
            'market' => Market::BeNl,
            'visibility' => ListVisibility::Link,
        ]);
    }

    // --- The standard "My wishlist" -----------------------------------------

    #[Test]
    public function everyone_gets_one_wishlist_on_their_first_visit(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/be-nl/lists')->assertOk();

        $lists = Wishlist::query()->where('owner_user_id', $user->id)->get();

        $this->assertCount(1, $lists);
        $this->assertTrue($lists->first()->is_default);
    }

    #[Test]
    public function visiting_twice_does_not_make_a_second_one(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/be-nl/lists');
        $this->actingAs($user)->get('/be-nl/lists');

        // Two defaults would make "where did my save go?" unanswerable again,
        // which is the question this exists to settle.
        $this->assertSame(1, Wishlist::query()->where('owner_user_id', $user->id)->count());
    }

    #[Test]
    public function an_existing_list_is_adopted_rather_than_duplicated(): void
    {
        $user = User::factory()->create();

        $existing = Wishlist::factory()->create([
            'owner_user_id' => $user->id,
            'kind' => ListKind::Mine,
            'market' => Market::BeNl,
            'title' => 'Saved items',
        ]);

        WishlistItem::factory()->create(['wishlist_id' => $existing->id]);

        $this->actingAs($user)->get('/be-nl/lists');

        // People who already had a list keep it, with its things in it, rather
        // than finding a new empty one beside it.
        $this->assertSame(1, Wishlist::query()->where('owner_user_id', $user->id)->count());
        $this->assertTrue($existing->fresh()->is_default);
    }

    // --- Handover ------------------------------------------------------------

    #[Test]
    public function a_gift_list_can_be_handed_to_the_person_it_is_about(): void
    {
        $giver = User::factory()->create();
        $person = User::factory()->create();

        $recipient = Recipient::factory()->create([
            'owner_user_id' => $giver->id,
            'user_id' => $person->id,
            'status' => RecipientStatus::Linked,
            'name' => 'Mum',
        ]);

        $list = Wishlist::factory()->create([
            'owner_user_id' => $giver->id,
            'recipient_id' => $recipient->id,
            'kind' => ListKind::ForSomeone,
            'market' => Market::BeNl,
        ]);

        WishlistCollaborator::create([
            'wishlist_id' => $list->id,
            'user_id' => User::factory()->create()->id,
            'role' => 'editor',
        ]);

        $this->actingAs($giver)
            ->post("/be-nl/lists/{$list->id}/handover")
            ->assertRedirect();

        $list->refresh();

        $this->assertSame($person->id, $list->owner_user_id);
        $this->assertSame(ListKind::Mine, $list->kind);
        $this->assertNull($list->recipient_id);
        $this->assertNotNull($list->handed_over_at);

        /*
         * Collaborators go. They were co-givers coordinating in private about
         * this exact person, and leaving them attached both tells the new owner
         * who was plotting and leaves those people reading what is now somebody
         * else's private list.
         */
        $this->assertSame(0, $list->collaborators()->count());
    }

    #[Test]
    public function a_list_cannot_be_handed_to_a_name(): void
    {
        $giver = User::factory()->create();

        // A stub recipient is a name the giver typed. Handing a list to a name
        // gives it to nobody and loses it.
        $recipient = Recipient::factory()->create(['owner_user_id' => $giver->id]);

        $list = Wishlist::factory()->create([
            'owner_user_id' => $giver->id,
            'recipient_id' => $recipient->id,
            'kind' => ListKind::ForSomeone,
            'market' => Market::BeNl,
        ]);

        $this->actingAs($giver)
            ->post("/be-nl/lists/{$list->id}/handover")
            ->assertStatus(422);

        $this->assertNull($list->fresh()->handed_over_at);
    }

    #[Test]
    public function your_own_list_cannot_be_handed_over(): void
    {
        $user = User::factory()->create();
        $list = $this->sharedList($user);

        $this->actingAs($user)
            ->post("/be-nl/lists/{$list->id}/handover")
            ->assertForbidden();
    }

    // --- Group gift ----------------------------------------------------------

    #[Test]
    public function several_people_can_pledge_towards_one_item(): void
    {
        $owner = User::factory()->create();
        $list = $this->sharedList($owner);
        $item = WishlistItem::factory()->create(['wishlist_id' => $list->id]);

        foreach (['Ann' => 25, 'Bob' => 30] as $name => $amount) {
            $this->actingAs(User::factory()->create())
                ->post("/be-nl/l/{$list->share_token}/pledge/{$item->id}", [
                    'amount' => $amount,
                    'display_name' => $name,
                ])
                ->assertRedirect();
        }

        // Cents, per invariant #7.
        $this->assertSame(5500, (int) GiftPledge::query()->sum('amount'));
    }

    #[Test]
    public function pledging_twice_changes_your_mind_rather_than_adding_again(): void
    {
        $owner = User::factory()->create();
        $list = $this->sharedList($owner);
        $item = WishlistItem::factory()->create(['wishlist_id' => $list->id]);
        $giver = User::factory()->create();

        foreach ([25, 40] as $amount) {
            $this->actingAs($giver)->post("/be-nl/l/{$list->share_token}/pledge/{$item->id}", [
                'amount' => $amount,
                'display_name' => 'Ann',
            ]);
        }

        $this->assertSame(1, GiftPledge::query()->count());
        $this->assertSame(4000, GiftPledge::query()->first()->amount);
    }

    #[Test]
    public function the_list_owner_cannot_pledge_on_their_own_list(): void
    {
        $owner = User::factory()->create();
        $list = $this->sharedList($owner);
        $item = WishlistItem::factory()->create(['wishlist_id' => $list->id]);

        // A pledge is claim state: telling the owner that four people put money
        // against the bicycle tells them about the bicycle.
        $this->actingAs($owner)
            ->post("/be-nl/l/{$list->share_token}/pledge/{$item->id}", [
                'amount' => 25,
                'display_name' => 'Me',
            ])
            ->assertForbidden();
    }

    #[Test]
    public function a_research_list_cannot_be_pledged_on(): void
    {
        $owner = User::factory()->create();

        $list = Wishlist::factory()->create([
            'owner_user_id' => $owner->id,
            'recipient_id' => Recipient::factory()->create(['owner_user_id' => $owner->id])->id,
            'kind' => ListKind::ForSomeone,
            'market' => Market::BeNl,
            'visibility' => ListVisibility::Link,
        ]);

        $item = WishlistItem::factory()->create(['wishlist_id' => $list->id]);

        $this->actingAs(User::factory()->create())
            ->post("/be-nl/l/{$list->share_token}/pledge/{$item->id}", [
                'amount' => 25,
                'display_name' => 'Ann',
            ])
            ->assertForbidden();
    }

    // --- Registry ------------------------------------------------------------

    #[Test]
    public function a_list_becomes_a_registry_with_an_occasion_and_a_date(): void
    {
        $user = User::factory()->create();
        $list = $this->sharedList($user);

        $this->actingAs($user)
            ->patch("/be-nl/lists/{$list->id}", [
                'event_type' => 'wedding',
                'event_date' => '2027-06-12',
                'delivery_address' => '12 Somewhere Street, Ghent',
            ])
            ->assertRedirect();

        $list->refresh();

        $this->assertTrue($list->isRegistry());
        $this->assertSame('12 Somewhere Street, Ghent', $list->delivery_address);
    }

    #[Test]
    public function the_delivery_address_is_encrypted_at_rest(): void
    {
        $user = User::factory()->create();
        $list = $this->sharedList($user);

        $this->actingAs($user)->patch("/be-nl/lists/{$list->id}", [
            'delivery_address' => '12 Somewhere Street, Ghent',
        ]);

        $raw = (string) DB::table('wishlists')
            ->where('id', $list->id)
            ->value('delivery_address');

        // A home address cannot be rotated the way a password can. A database
        // copy must not be a list of where people live.
        $this->assertNotSame('', $raw);
        $this->assertStringNotContainsString('Somewhere Street', $raw);
    }

    #[Test]
    public function a_visitor_never_receives_the_delivery_address(): void
    {
        $user = User::factory()->create();
        $list = $this->sharedList($user);

        $this->actingAs($user)->patch("/be-nl/lists/{$list->id}", [
            'delivery_address' => '12 Somewhere Street, Ghent',
        ]);

        auth()->logout();

        $response = $this->get("/be-nl/l/{$list->share_token}")->assertOk();

        $this->assertStringNotContainsString(
            'Somewhere Street',
            json_encode($response->viewData('page')['props']),
        );
    }

    // --- Suggestions ---------------------------------------------------------

    #[Test]
    public function a_suggestion_is_not_on_the_list_until_it_is_accepted(): void
    {
        $owner = User::factory()->create();
        $list = $this->sharedList($owner);
        $group = ProductGroup::factory()->create(['market' => Market::BeNl]);

        $this->actingAs(User::factory()->create())
            ->post("/be-nl/l/{$list->share_token}/suggest", ['group_id' => $group->id])
            ->assertRedirect();

        // On the table, and not on the list — so no shared surface, no quiz and
        // no claim can reach it.
        $this->assertSame(1, $list->allItems()->count());
        $this->assertSame(0, $list->items()->count());
        $this->assertSame(1, $list->suggestions()->count());
    }

    #[Test]
    public function accepting_a_suggestion_puts_it_on_the_list(): void
    {
        $owner = User::factory()->create();
        $list = $this->sharedList($owner);
        $group = ProductGroup::factory()->create(['market' => Market::BeNl]);

        $this->actingAs(User::factory()->create())
            ->post("/be-nl/l/{$list->share_token}/suggest", ['group_id' => $group->id]);

        $suggestion = $list->suggestions()->firstOrFail();

        $this->actingAs($owner)
            ->post("/be-nl/suggestions/{$suggestion->id}/accept")
            ->assertRedirect();

        $this->assertSame(1, $list->items()->count());
    }

    #[Test]
    public function only_the_owner_decides_what_goes_on_their_list(): void
    {
        $owner = User::factory()->create();
        $list = $this->sharedList($owner);
        $group = ProductGroup::factory()->create(['market' => Market::BeNl]);

        $this->actingAs(User::factory()->create())
            ->post("/be-nl/l/{$list->share_token}/suggest", ['group_id' => $group->id]);

        $suggestion = $list->suggestions()->firstOrFail();

        $this->actingAs(User::factory()->create())
            ->post("/be-nl/suggestions/{$suggestion->id}/accept")
            ->assertNotFound();
    }

    #[Test]
    public function nobody_can_suggest_into_private_research_about_a_person(): void
    {
        $owner = User::factory()->create();

        $list = Wishlist::factory()->create([
            'owner_user_id' => $owner->id,
            'recipient_id' => Recipient::factory()->create(['owner_user_id' => $owner->id])->id,
            'kind' => ListKind::ForSomeone,
            'market' => Market::BeNl,
            'visibility' => ListVisibility::Link,
        ]);

        $this->actingAs(User::factory()->create())
            ->post("/be-nl/l/{$list->share_token}/suggest", [
                'group_id' => ProductGroup::factory()->create(['market' => Market::BeNl])->id,
            ])
            ->assertForbidden();
    }

    // --- Display name --------------------------------------------------------

    #[Test]
    public function a_nameless_account_is_called_something_that_is_not_their_address(): void
    {
        $user = User::factory()->create(['name' => null, 'email' => 'ann@example.test']);

        // A share link lands with strangers. Printing a full address on it hands
        // it to everyone who ever sees the page.
        $this->assertSame('ann', $user->displayName());
    }

    #[Test]
    public function a_name_given_at_sign_in_reaches_the_account(): void
    {
        $this->post('/be-nl/login', ['email' => 'new@example.test', 'name' => 'Ann Smith'])
            ->assertRedirect();

        $token = LoginToken::query()->firstOrFail();

        $this->assertSame('Ann Smith', $token->name);
    }
}
