<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ClaimVisibility;
use App\Enums\EventType;
use App\Enums\ListKind;
use App\Enums\ListVisibility;
use App\Enums\Market;
use App\Enums\RecipientStatus;
use App\Http\Middleware\TrackAnonymousIdentity;
use App\Models\AnonymousIdentity;
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
    public function the_default_lists_name_follows_the_market_it_is_read_on(): void
    {
        $user = User::factory()->create();

        // Created on English, so the row stores "My wishlist" forever.
        $this->actingAs($user)->get('/en/lists')->assertOk();

        $list = Wishlist::query()->where('owner_user_id', $user->id)->sole();
        $this->assertSame('My wishlist', $list->title);

        /*
         * Read on a Dutch market it must say so anyway. The title is stored,
         * not chosen — freezing it in the language of whichever market the
         * owner first landed on left an English name sitting among Dutch pages
         * for anyone who switched.
         */
        $this->actingAs($user)
            ->get('/be-nl/lists')
            ->assertInertia(fn ($page) => $page->where('lists.0.title', 'Mijn wenslijst'));

        // And the stored value is untouched: this is a rendering decision.
        $this->assertSame('My wishlist', $list->fresh()->title);
    }

    #[Test]
    public function a_title_the_owner_typed_is_never_translated(): void
    {
        $user = User::factory()->create();

        $list = Wishlist::factory()->create([
            'owner_user_id' => $user->id,
            'kind' => ListKind::Mine,
            'market' => Market::BeNl,
            'is_default' => true,
            'title' => 'Boeken',
        ]);

        $this->actingAs($user)
            ->get('/en/lists')
            ->assertInertia(fn ($page) => $page->where('lists.0.title', 'Boeken'));

        $this->assertSame('Boeken', $list->fresh()->title);
    }

    #[Test]
    public function a_shared_default_list_is_named_after_its_owner_in_any_language(): void
    {
        $owner = User::factory()->create(['name' => 'Sanne']);

        $list = Wishlist::factory()->create([
            'owner_user_id' => $owner->id,
            'kind' => ListKind::Mine,
            'market' => Market::BeNl,
            'visibility' => ListVisibility::Link,
            'is_default' => true,
            // Stored in English; the reader below is on a Dutch market.
            'title' => 'My wishlist',
        ]);

        /*
         * The heading replacement used to compare the stored title against the
         * *active* locale's spelling, so a list created on `/en` was not
         * recognised as ours on `/be-nl` and the link went out titled "My
         * wishlist" — belonging to nobody, which is the exact thing this branch
         * exists to prevent.
         */
        $this->get("/be-nl/l/{$list->share_token}")
            ->assertInertia(fn ($page) => $page->where('list.heading', 'Wenslijst van Sanne'));
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
            ->assertInertia(fn ($page) => $page->where('wishlists.0.shared', false));
    }

    #[Test]
    public function the_gift_cove_works_signed_out(): void
    {
        // Somebody has to be able to read what this offers before deciding to
        // sign up for it.
        $this->get('/be-nl/gift-cove')->assertOk();
    }

    #[Test]
    public function the_manual_is_its_own_page_and_the_hub_links_to_it(): void
    {
        /*
         * It used to be the bottom half of the hub, behind a `#manual` anchor.
         * Two readers wanting opposite things on one page — one here to use a
         * tool, one to understand it — and the second had to scroll past the
         * first. A page also gives the explanation an address that an email or
         * a search result can point at, which an anchor never had.
         *
         * Public, like the hub: somebody has to be able to read what this
         * offers before deciding to sign up for it.
         */
        $this->get('/be-nl/gift-cove/how-it-works')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('GiftCove/HowItWorks'));

        // And the hub still points at it. A manual nothing links to is a manual
        // nobody reads, which is worse than one at the bottom of a long page.
        $this->get('/be-nl/gift-cove')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('urls.manual', '/be-nl/gift-cove/how-it-works'));
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

    /**
     * Half of a distinction, not a statement about the feature.
     *
     * This used to be called `a_visitor_never_receives_the_delivery_address`
     * and it asserted the absence of a feature the copy had been promising in
     * two places since the day it shipped. The body is unchanged; what changed
     * is that there is now a matching test for the other half.
     */
    #[Test]
    public function a_visitor_who_has_claimed_nothing_never_receives_the_delivery_address(): void
    {
        $user = User::factory()->create();
        $list = $this->registry($user);

        auth()->logout();

        $response = $this->get("/be-nl/l/{$list->share_token}")->assertOk();

        $this->assertStringNotContainsString(
            'Somewhere Street',
            json_encode($response->viewData('page')['props']),
        );
    }

    #[Test]
    public function a_visitor_who_has_claimed_something_is_given_the_delivery_address(): void
    {
        // The whole point of a registry: somebody has to be able to post the
        // parcel. `registry.address_hint` has promised exactly this to the
        // owner since the feature shipped.
        $user = User::factory()->create();
        $list = $this->registry($user);
        $item = WishlistItem::factory()->create(['wishlist_id' => $list->id]);

        auth()->logout();

        // One identity across both requests: the gate reads the visitor's own
        // claim hash, and the test client does not carry cookies between calls.
        $visitor = AnonymousIdentity::create(['last_seen_at' => now()]);

        $this->withCookie(TrackAnonymousIdentity::COOKIE, (string) $visitor->getKey())
            ->post("/be-nl/l/{$list->share_token}/claim/{$item->id}")
            ->assertRedirect();

        $props = $this->withCookie(TrackAnonymousIdentity::COOKIE, (string) $visitor->getKey())
            ->get("/be-nl/l/{$list->share_token}")
            ->assertOk()
            ->viewData('page')['props'];

        $this->assertSame('12 Somewhere Street, Ghent', $props['occasion']['address']);
        $this->assertFalse($props['occasion']['locked']);
    }

    #[Test]
    public function a_visitor_who_claimed_nothing_does_not_see_another_claimers_address(): void
    {
        // The gate is per-visitor, not per-list. One person claiming does not
        // publish the address to everybody else holding the link.
        $user = User::factory()->create();
        $list = $this->registry($user);
        $item = WishlistItem::factory()->create(['wishlist_id' => $list->id]);

        auth()->logout();

        $claimer = AnonymousIdentity::create(['last_seen_at' => now()]);
        $bystander = AnonymousIdentity::create(['last_seen_at' => now()]);

        $this->withCookie(TrackAnonymousIdentity::COOKIE, (string) $claimer->getKey())
            ->post("/be-nl/l/{$list->share_token}/claim/{$item->id}");

        $props = $this->withCookie(TrackAnonymousIdentity::COOKIE, (string) $bystander->getKey())
            ->get("/be-nl/l/{$list->share_token}")
            ->assertOk()
            ->viewData('page')['props'];

        $this->assertNull($props['occasion']['address']);
    }

    #[Test]
    public function releasing_a_claim_takes_the_address_away_again(): void
    {
        /*
         * Non-obvious and worth pinning: `WishlistItem::release()` nulls the
         * hash, so the gate closes on its own. Nothing revokes the address
         * explicitly, and nothing should have to.
         */
        $user = User::factory()->create();
        $list = $this->registry($user);
        $item = WishlistItem::factory()->create(['wishlist_id' => $list->id]);

        auth()->logout();

        $visitor = AnonymousIdentity::create(['last_seen_at' => now()]);
        $cookie = (string) $visitor->getKey();

        $this->withCookie(TrackAnonymousIdentity::COOKIE, $cookie)
            ->post("/be-nl/l/{$list->share_token}/claim/{$item->id}");

        $this->withCookie(TrackAnonymousIdentity::COOKIE, $cookie)
            ->delete("/be-nl/l/{$list->share_token}/claim/{$item->id}");

        $props = $this->withCookie(TrackAnonymousIdentity::COOKIE, $cookie)
            ->get("/be-nl/l/{$list->share_token}")
            ->assertOk()
            ->viewData('page')['props'];

        $this->assertNull($props['occasion']['address']);
        $this->assertTrue($props['occasion']['locked']);
    }

    #[Test]
    public function the_occasion_and_date_are_shown_to_everybody_holding_the_link(): void
    {
        // Not gated: they are why the list exists. Only the address is.
        $user = User::factory()->create();
        $list = $this->registry($user);

        auth()->logout();

        $props = $this->get("/be-nl/l/{$list->share_token}")->assertOk()->viewData('page')['props'];

        $this->assertSame(__('site.registry.types.wedding'), $props['occasion']['name']);
        $this->assertNotNull($props['occasion']['date']);
        $this->assertNull($props['occasion']['address']);
    }

    #[Test]
    public function the_owner_is_not_given_the_address_through_the_shared_view(): void
    {
        // They get it on their own page. The shared branch must not become a
        // second supplier, because that is the branch that would eventually be
        // asked "has anybody claimed?" to decide.
        $user = User::factory()->create();
        $list = $this->registry($user);

        $props = $this->actingAs($user)
            ->get("/be-nl/l/{$list->share_token}")
            ->assertOk()
            ->viewData('page')['props'];

        $this->assertNull($props['occasion']['address']);
    }

    #[Test]
    public function an_ordinary_list_carries_no_occasion_block(): void
    {
        $list = $this->sharedList(User::factory()->create());

        auth()->logout();

        $props = $this->get("/be-nl/l/{$list->share_token}")->assertOk()->viewData('page')['props'];

        $this->assertNull($props['occasion']);
    }

    /** A shared wish list with an occasion, a date and somewhere to post it. */
    #[Test]
    public function every_occasion_the_enum_offers_is_one_the_database_accepts(): void
    {
        /*
         * The one way this pair goes wrong, and it goes wrong silently.
         *
         * `wishlists_event_type_check` is built from `EventType::values()` in
         * `2026_08_09_002100`, and widened by a *literal* list in
         * `2026_08_29_000100`. So the enum and the constraint are written down
         * twice, in two places, and adding a case to the enum alone leaves a
         * value the application offers and the database rejects — a dropdown
         * option that throws a QueryException the moment somebody picks it.
         *
         * Nothing else would catch it: the enum is valid PHP, the validator
         * builds its `in:` rule from the same enum and passes it, and every
         * existing test picks `wedding`.
         */
        $owner = User::factory()->create();

        foreach (EventType::cases() as $type) {
            $list = Wishlist::factory()->create([
                'owner_user_id' => $owner->id,
                'owner_anon_id' => null,
                'market' => Market::BeNl->value,
                'event_type' => $type->value,
            ]);

            $this->assertSame(
                $type,
                $list->fresh()->event_type,
                "The database rejected or altered the occasion '{$type->value}'. "
                .'Adding a case to EventType needs a migration widening '
                .'wishlists_event_type_check.',
            );
        }
    }

    #[Test]
    public function handing_over_a_claimed_list_keeps_the_claim_and_drops_the_name(): void
    {
        /*
         * `HandoverController`'s docblock used to say "there are no claims to
         * worry about: a `for_someone` list is not claimable in the first
         * place". That went false the day gift lists became claimable, and it
         * is the kind of sentence that goes on being believed.
         *
         * **The claim stays.** A sibling may already have bought the thing, and
         * releasing it sends a second person to the shops. The new owner never
         * learns of it: the list is `mine` now, so claims are hidden from them
         * absolutely.
         *
         * **The name goes.** It was typed for a small audience of co-givers
         * plotting a surprise. The list is now a wish list its owner may share
         * with anyone, and consent to the first audience is not consent to the
         * second.
         */
        $giver = User::factory()->create();
        $recipient = Recipient::factory()->create(['owner_user_id' => $giver->id]);

        $list = Wishlist::factory()->create([
            'owner_user_id' => $giver->id,
            'recipient_id' => $recipient->id,
            'kind' => ListKind::ForSomeone,
            'market' => Market::BeNl,
            'visibility' => ListVisibility::Link,
            'claim_visibility' => ClaimVisibility::Named,
        ]);

        $item = WishlistItem::factory()->create(['wishlist_id' => $list->id]);
        $item->claim(WishlistItem::identityHash('anon:a-sibling'), 'Anna');

        $newOwner = User::factory()->create();

        $this->actingAs($giver)
            ->post("/be-nl/lists/{$list->id}/handover", ['email' => $newOwner->email])
            ->assertRedirect();

        $item->refresh();

        $this->assertNotNull($item->claimed_by_hash, 'Somebody may already have bought it.');
        $this->assertNull($item->claimed_by_name, 'The name was given to a different audience.');

        $list->refresh();
        $this->assertSame(ClaimVisibility::Anonymous, $list->claim_visibility);
        $this->assertNull(
            $list->owner_sees_claims,
            'Back to "never asked": inheriting the giver\'s choice would decide '
            .'for the new owner whether their own list surprises them.',
        );

        // And the new owner — the person the surprise is for — sees none of it.
        $this->actingAs($newOwner)
            ->get("/be-nl/l/{$list->share_token}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page->missing('items.0.claimed'));
    }

    private function registry(User $owner): Wishlist
    {
        $list = $this->sharedList($owner);

        $this->actingAs($owner)->patch("/be-nl/lists/{$list->id}", [
            'event_type' => 'wedding',
            'event_date' => now()->addMonths(2)->toDateString(),
            'delivery_address' => '12 Somewhere Street, Ghent',
        ]);

        return $list->fresh();
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
    public function a_gift_list_invites_the_people_on_it_to_add(): void
    {
        /*
         * This test used to be `a_research_list_offers_nobody_the_suggest_control`
         * and asserted the opposite, on the grounds that "suggesting into
         * private research about a person would tell a stranger it exists".
         *
         * The premise does not hold. `findShared()` refuses a private list, so
         * nobody reaches this page by accident — everybody here was **sent the
         * link on purpose**, and helping fill the list is why. The rule was
         * protecting research from the very people the owner had invited to
         * help with it.
         *
         * `addsDirectly` is the other half: on a list about a third person the
         * owner is a co-giver rather than the subject, so an addition goes
         * straight on instead of into an approval queue they would have to
         * empty.
         */
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
            ->assertInertia(fn ($page) => $page
                ->where('canSuggest', true)
                ->where('addsDirectly', true));
    }

    #[Test]
    public function a_wish_list_still_puts_what_a_visitor_adds_in_the_queue(): void
    {
        // The other side of the same rule. On somebody's own wish list an
        // addition is a message *to them about their list*, and the
        // accept/dismiss row is the entire point of it.
        $list = $this->sharedList(User::factory()->create());

        $this->actingAs(User::factory()->create())
            ->get("/be-nl/l/{$list->share_token}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('canSuggest', true)
                ->where('addsDirectly', false));
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
    public function a_product_added_to_a_gift_list_goes_straight_on_it(): void
    {
        /*
         * The replacement for `nobody_can_suggest_into_private_research_about_a_person`.
         *
         * `accepted_at` is what is really being asserted: `Wishlist::items()`
         * filters on it, so a pending row is on no page anybody can see. If
         * this regressed, the helper would press Add, get a success message,
         * and nothing would appear — for them or for anyone else — until an
         * owner who was never told found a queue.
         */
        $owner = User::factory()->create();

        $list = Wishlist::factory()->create([
            'owner_user_id' => $owner->id,
            'recipient_id' => Recipient::factory()->create(['owner_user_id' => $owner->id])->id,
            'kind' => ListKind::ForSomeone,
            'market' => Market::BeNl,
            'visibility' => ListVisibility::Link,
        ]);

        $helper = User::factory()->create();

        $this->actingAs($helper)
            ->post("/be-nl/l/{$list->share_token}/suggest", [
                'group_id' => ProductGroup::factory()->create(['market' => Market::BeNl])->id,
            ])
            ->assertRedirect();

        $item = $list->items()->sole();

        $this->assertNotNull($item->accepted_at);
        $this->assertSame(
            $helper->id,
            $item->suggested_by_user_id,
            'A shared workspace where nobody can tell who added what invites two people deleting each other\'s finds.',
        );
    }

    #[Test]
    public function a_hand_written_item_from_a_visitor_still_waits(): void
    {
        /*
         * The one judgement call in this change, pinned.
         *
         * A catalogue product is a `group_id` — structured, ours, nothing to
         * moderate. A typed title and price is free text arriving from a link
         * that can be forwarded anywhere, which is the moderation surface
         * `wishlists.md` deliberately declined to open. It stays behind the
         * queue on every kind of list, including the ones that take catalogue
         * items directly.
         */
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
                'title' => 'A voucher for the climbing gym',
                'price' => 5000,
            ])
            ->assertRedirect();

        $this->assertSame(0, $list->items()->count(), 'It must not be on the list yet.');
        $this->assertSame(1, $list->allItems()->count(), 'But it must exist, waiting.');
    }

    #[Test]
    public function the_owner_of_a_gift_list_cannot_add_through_the_share_link(): void
    {
        // Contributing to yourself is just adding, and the list page does that
        // without the round trip. This used to be gated on
        // `shouldHideClaimsFrom()`, which stopped meaning "is the owner" the
        // moment a gift list's owner could see claims — so without the change
        // to `isOwnedBy()` they could suggest into their own list.
        $owner = User::factory()->create();

        $list = Wishlist::factory()->create([
            'owner_user_id' => $owner->id,
            'recipient_id' => Recipient::factory()->create(['owner_user_id' => $owner->id])->id,
            'kind' => ListKind::ForSomeone,
            'market' => Market::BeNl,
            'visibility' => ListVisibility::Link,
        ]);

        $this->actingAs($owner)
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
