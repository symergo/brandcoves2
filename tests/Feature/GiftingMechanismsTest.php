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

    #[Test]
    public function the_gift_cove_renders_on_a_first_ever_visit(): void
    {
        /*
         * The case that broke it: the page creates the default list and then
         * reads it back. `create()` returns the model it built in memory, so a
         * value only the database knows about — `visibility`, which has a column
         * default — was null on that instance and took the page down with a 500
         * for every brand new account.
         */
        $this->actingAs(User::factory()->create())
            ->get('/be-nl/gift-cove')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('mine.shared', false));
    }

    #[Test]
    public function the_gift_cove_works_signed_out(): void
    {
        // Somebody has to be able to read what this offers before deciding to
        // sign up for it.
        $this->get('/be-nl/gift-cove')->assertOk();
    }

    #[Test]
    public function every_tool_on_the_gift_cove_has_its_three_steps(): void
    {
        /*
         * The manual is nine tools × three steps, and a missing string renders
         * as its own key — `gift_cove.quiz_step2` in the middle of a numbered
         * list, in whichever market lost it. `LocalisationTest` catches a
         * language that falls behind English; this catches the other direction,
         * a tenth tool added to the page with no steps written for it.
         */
        $tools = ['wishlist', 'giftlist', 'collab', 'handover', 'santa', 'registry', 'quiz', 'suggestions', 'whisperer'];

        foreach ($tools as $tool) {
            foreach ([1, 2, 3] as $step) {
                $key = "site.gift_cove.{$tool}_step{$step}";

                $this->assertNotSame($key, __($key), "The Gift Cove manual has no {$key}.");
            }
        }
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
    public function a_list_can_be_handed_over_by_email(): void
    {
        $giver = User::factory()->create();
        $person = User::factory()->create(['email' => 'sarah@example.test']);

        $recipient = Recipient::factory()->create(['owner_user_id' => $giver->id, 'name' => 'Sarah']);

        $list = Wishlist::factory()->create([
            'owner_user_id' => $giver->id,
            'recipient_id' => $recipient->id,
            'kind' => ListKind::ForSomeone,
            'market' => Market::BeNl,
        ]);

        /*
         * No prior linking. Handover used to require the recipient to have
         * claimed their /for/{token} link, which nobody had done — so the button
         * never appeared and a working feature read as a broken one.
         */
        $this->actingAs($giver)
            ->post("/be-nl/lists/{$list->id}/handover", ['email' => 'SARAH@example.test'])
            ->assertRedirect();

        $this->assertSame($person->id, $list->fresh()->owner_user_id);
    }

    #[Test]
    public function handing_over_to_an_address_with_no_account_says_so(): void
    {
        $giver = User::factory()->create();

        $list = Wishlist::factory()->create([
            'owner_user_id' => $giver->id,
            'recipient_id' => Recipient::factory()->create(['owner_user_id' => $giver->id])->id,
            'kind' => ListKind::ForSomeone,
            'market' => Market::BeNl,
        ]);

        // Unlike the collaborator invite, this one tells you: the owner is
        // giving something away and has to know whether it landed.
        $this->actingAs($giver)
            ->post("/be-nl/lists/{$list->id}/handover", ['email' => 'nobody@example.test'])
            ->assertStatus(422);

        $this->assertNull($list->fresh()->handed_over_at);
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
    public function a_visitor_is_offered_the_suggest_control_and_the_owner_is_not(): void
    {
        /*
         * The endpoint shipped without any way to reach it: no page ever posted
         * to `/l/{token}/suggest`, while `suggestions.suggest` sat translated
         * into four languages and rendered nowhere. Every test around it passed
         * throughout, because they post to the endpoint directly — which is
         * exactly why this one asserts on what the *page* is given.
         */
        $owner = User::factory()->create();
        $list = $this->sharedList($owner);

        $this->actingAs(User::factory()->create())
            ->get("/be-nl/l/{$list->share_token}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('canSuggest', true)->where('results', null));

        // Suggesting to yourself is just adding, and the list page does that
        // without the round trip through approval.
        $this->actingAs($owner)
            ->get("/be-nl/l/{$list->share_token}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('canSuggest', false));
    }

    #[Test]
    public function a_research_list_offers_nobody_the_suggest_control(): void
    {
        // Suggesting into private research about a person would tell a stranger
        // it exists. The endpoint refuses it; the control must not appear either.
        $owner = User::factory()->create();

        $list = Wishlist::factory()->create([
            'owner_user_id' => $owner->id,
            'recipient_id' => Recipient::factory()->create(['owner_user_id' => $owner->id])->id,
            'kind' => ListKind::ForSomeone,
            'market' => Market::BeNl,
            'visibility' => ListVisibility::Link,
        ]);

        $this->actingAs(User::factory()->create())
            ->get("/be-nl/l/{$list->share_token}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('canSuggest', false));
    }

    #[Test]
    public function searching_inside_somebody_elses_list_is_not_public_demand(): void
    {
        /*
         * `search_log` is not a debug record: it feeds the related-search chips
         * on public narrative pages and the demand signal that picks which
         * buying guides get written. A term typed into a friend's shared gift
         * list is about one named person on an unauthenticated private URL, and
         * "engagement ring" resurfacing as a suggested search elsewhere is the
         * kind of leak nobody would connect back to this feature.
         */
        $list = $this->sharedList(User::factory()->create());

        $this->actingAs(User::factory()->create())
            ->get("/be-nl/l/{$list->share_token}?q=engagement+ring")
            ->assertOk();

        $this->assertSame(0, DB::table('search_log')->count());
    }

    #[Test]
    public function suggesting_something_already_on_the_list_does_not_take_it_off(): void
    {
        /*
         * `ItemSaver::saveGroup()` is an `updateOrCreate` on
         * `(wishlist_id, group_id)` and `SuggestionController` nulls
         * `accepted_at` straight afterwards — so a suggestion naming a product
         * the owner already has would have moved a real item back to pending,
         * claim and all.
         *
         * Unreachable while the feature had no UI, and the ordinary case the
         * moment a visitor can search for something: the obvious thing to
         * suggest is the obvious thing to already own.
         */
        $owner = User::factory()->create();
        $list = $this->sharedList($owner);
        $group = ProductGroup::factory()->create(['market' => Market::BeNl]);

        WishlistItem::factory()->create([
            'wishlist_id' => $list->id,
            'group_id' => $group->id,
            'accepted_at' => now(),
        ]);

        $this->actingAs(User::factory()->create())
            ->post("/be-nl/l/{$list->share_token}/suggest", ['group_id' => $group->id])
            ->assertRedirect();

        $this->assertSame(1, $list->items()->count());
        $this->assertSame(0, $list->suggestions()->count());
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

    // --- Something we do not sell -------------------------------------------

    #[Test]
    public function a_list_can_hold_something_typed_in_by_hand(): void
    {
        // The catalogue is not the world. A voucher for the climbing gym, the
        // local bike shop, one particular edition of a book — a list that
        // cannot hold those is a list with the real present missing.
        $user = User::factory()->create();
        $list = $this->sharedList($user);

        $this->actingAs($user)
            ->post('/be-nl/list-items', [
                'source' => 'manual',
                'wishlist_id' => $list->id,
                'title' => 'A voucher for the climbing gym',
                'url' => 'https://example.test/vouchers/climbing',
                'price' => 4000,
            ])
            ->assertRedirect();

        $item = $list->items()->sole();

        $this->assertSame('A voucher for the climbing gym', $item->snapshot_title);
        $this->assertSame('https://example.test/vouchers/climbing', $item->externalUrl());
        $this->assertSame(4000, $item->snapshot_price);

        // No image, deliberately: it would be fetched by every visitor's
        // browser from a host the owner chose, which is a view-tracking pixel
        // on a page whose entire point is that the owner learns nothing.
        $this->assertNull($item->snapshot_image_url);
    }

    #[Test]
    public function a_hand_written_link_that_is_not_https_is_refused(): void
    {
        /*
         * Invariant #5, at the point it bites hardest: this URL is typed by a
         * person and rendered on a page other people open from a link they were
         * sent. HTML escaping does not help — `javascript:` survives it intact
         * and runs on click.
         */
        $user = User::factory()->create();
        $list = $this->sharedList($user);

        foreach (['javascript:alert(1)', 'data:text/html,<script>x</script>', 'http://example.test/x'] as $url) {
            /*
             * A redirect carrying errors, not a 422: this app renders JSON
             * errors for `api/*` only (see bootstrap/app.php), so a rejected
             * web form always comes back through the session — which is also
             * where Inertia reads them from to put the message on the field.
             */
            $this->actingAs($user)
                ->post('/be-nl/list-items', [
                    'source' => 'manual',
                    'wishlist_id' => $list->id,
                    'title' => 'A thing',
                    'url' => $url,
                ])
                ->assertRedirect()
                ->assertSessionHasErrors('url');
        }

        $this->assertSame(0, $list->items()->count());
    }

    #[Test]
    public function a_visitor_can_suggest_something_we_do_not_sell(): void
    {
        // The thing somebody most wants to put forward is often the thing we do
        // not stock. Same saver, same pending state, same accept row.
        $list = $this->sharedList(User::factory()->create());

        $this->actingAs(User::factory()->create())
            ->post("/be-nl/l/{$list->share_token}/suggest", [
                'title' => 'That cookbook she mentioned',
                'url' => 'https://example.test/cookbook',
            ])
            ->assertRedirect();

        $suggestion = $list->suggestions()->sole();

        $this->assertSame('That cookbook she mentioned', $suggestion->snapshot_title);
        $this->assertNull($suggestion->accepted_at);
        $this->assertSame(0, $list->items()->count());
    }

    #[Test]
    public function an_unsafe_link_is_refused_from_the_suggest_endpoint_too(): void
    {
        // Two entry points, one rule. The visitor-facing one is the one a
        // stranger can reach, so it is the one that must not be the lenient copy.
        $list = $this->sharedList(User::factory()->create());

        $this->actingAs(User::factory()->create())
            ->post("/be-nl/l/{$list->share_token}/suggest", [
                'title' => 'A thing',
                'url' => 'javascript:alert(1)',
            ])
            ->assertRedirect()
            ->assertSessionHasErrors('url');

        $this->assertSame(0, $list->allItems()->count());
    }

    #[Test]
    public function a_stored_link_that_is_not_https_never_reaches_the_page(): void
    {
        /*
         * Belt and braces, and not theatre: rows predating the rule, a future
         * import, or a second write path added later would all bypass the
         * validator. The render asks the model, and the model refuses.
         */
        $list = $this->sharedList(User::factory()->create());

        $item = WishlistItem::factory()->create([
            'wishlist_id' => $list->id,
            'group_id' => null,
            'source' => 'manual',
            'external_id' => null,
            'snapshot_title' => 'A thing',
            'snapshot_url' => 'javascript:alert(1)',
            'accepted_at' => now(),
        ]);

        $this->assertNull($item->externalUrl());

        $this->get("/be-nl/l/{$list->share_token}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('items.0.externalUrl', null));
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
