<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ListKind;
use App\Enums\ListVisibility;
use App\Enums\Market;
use App\Http\Middleware\TrackAnonymousIdentity;
use App\Models\AnonymousIdentity;
use App\Models\GiftPledge;
use App\Models\ListItemVote;
use App\Models\Recipient;
use App\Models\User;
use App\Models\Wishlist;
use App\Models\WishlistCollaborator;
use App\Models\WishlistItem;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Group lists, and the money pooled against them.
 *
 * Two features that existed on paper and could not be reached: `ListKind::Group`
 * was a legal value that no creation path could write, and `GiftPledge` had a
 * controller, two routes and copy in four languages with nothing rendering it.
 *
 * The privacy inversion is what most of this file is about. On a `mine` list the
 * owner is the person being surprised and must never see contributions
 * (invariant #4); on a `group` list the owner is the organiser and must, while
 * the members must never see each other's amounts.
 */
class GroupListTest extends TestCase
{
    use RefreshDatabase;

    private function groupList(User $organiser, string $for = 'Dad'): Wishlist
    {
        return Wishlist::factory()->create([
            'owner_user_id' => $organiser->id,
            'recipient_id' => Recipient::factory()->create([
                'owner_user_id' => $organiser->id,
                'name' => $for,
            ])->id,
            'kind' => ListKind::Group,
            'market' => Market::BeNl,
            'visibility' => ListVisibility::Link,
        ]);
    }

    /** @return array<string, mixed> */
    private function props(TestResponse $response): array
    {
        return $response->viewData('page')['props'];
    }

    // --- Creating one --------------------------------------------------------

    #[Test]
    public function a_group_list_can_be_created_from_the_lists_page(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post('/be-nl/lists', [
                'title' => 'Dad’s bike',
                'new_recipient' => 'Dad',
                'together' => true,
            ])
            ->assertRedirect();

        $list = Wishlist::query()->where('title', 'Dad’s bike')->firstOrFail();

        $this->assertSame(ListKind::Group, $list->kind);
        $this->assertNotNull($list->recipient_id);
    }

    #[Test]
    public function the_save_picker_can_start_one(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post('/be-nl/list-items', [
            'source' => 'manual',
            'title' => 'A very good bike',
            'new_list' => 'Dad’s bike',
            'new_recipient' => 'Dad',
            'together' => true,
        ])->assertRedirect();

        $this->assertSame(
            ListKind::Group,
            Wishlist::query()->where('title', 'Dad’s bike')->firstOrFail()->kind,
        );
    }

    #[Test]
    public function together_without_a_person_is_a_list_for_yourself(): void
    {
        // "For me, together" is not a list. The recipient decides mine-vs-else
        // and `together` only chooses between the two ways of buying for
        // somebody, so this cannot produce a group list with nobody in it.
        $user = User::factory()->create();

        $this->actingAs($user)->post('/be-nl/lists', [
            'title' => 'Things',
            'together' => true,
        ]);

        $this->assertSame(
            ListKind::Mine,
            Wishlist::query()->where('title', 'Things')->firstOrFail()->kind,
        );
    }

    #[Test]
    public function the_database_refuses_a_group_list_with_no_recipient(): void
    {
        // The service guarantees this, and so does the schema — because the
        // service is one of two callers today and there is no promise it stays
        // two.
        $this->expectException(QueryException::class);

        Wishlist::factory()->create([
            'owner_user_id' => User::factory()->create()->id,
            'recipient_id' => null,
            'kind' => ListKind::Group,
            'market' => Market::BeNl,
        ]);
    }

    #[Test]
    public function the_group_view_is_no_longer_structurally_empty(): void
    {
        // `?view=group` shipped with the index and could never return a row,
        // because nothing could write the kind it filters on.
        $user = User::factory()->create();
        $list = $this->groupList($user);

        $response = $this->actingAs($user)->get('/be-nl/lists?view=group')->assertOk();

        $ids = array_column($this->props($response)['lists'], 'id');

        $this->assertContains($list->id, $ids);
    }

    #[Test]
    public function a_group_list_appears_under_my_lists_as_a_list_i_own(): void
    {
        /*
         * This asserted the opposite until 2026-08-29, on the grounds that
         * three views answer three questions and showing a group list in two of
         * them makes the sections decoration.
         *
         * The sections survived; the exclusion did not. My Lists is the page
         * somebody opens to find *a list*, and it was the one place a third of
         * their lists could not be found — the group view is one nav entry you
         * have to already know about. So the broad view is the superset and the
         * labelled section carries the distinction, which is what the section
         * was for.
         *
         * `?view=group` still answers the narrow question, and the row still
         * says it is mine rather than one somebody shared with me.
         */
        $user = User::factory()->create();
        $list = $this->groupList($user);

        $response = $this->actingAs($user)->get('/be-nl/lists')->assertOk();

        $row = collect($this->props($response)['lists'])->firstWhere('id', $list->id);

        $this->assertNotNull($row, 'A group list I own is missing from My Lists.');
        $this->assertSame(ListKind::Group->value, $row['kind']);
        $this->assertFalse($row['sharedWithMe']);
    }

    #[Test]
    public function the_shared_view_of_a_group_list_names_the_recipient(): void
    {
        /*
         * `Wishlist::isForSomeoneElse()` tested `ForSomeone` alone while the
         * enum's version included `Group`, so this page would have named the
         * *organiser* — telling the people buying the present that the list
         * belongs to the person it is a surprise for.
         */
        $organiser = User::factory()->create(['name' => 'Ann']);
        $list = $this->groupList($organiser, for: 'Dad');

        $response = $this->get("/be-nl/l/{$list->share_token}")->assertOk();

        $this->assertSame('Dad', $this->props($response)['list']['for']);
    }

    // --- Voting: which present the group buys --------------------------------

    #[Test]
    public function a_member_votes_for_a_candidate_and_can_take_it_back(): void
    {
        // Phase 3, and the half a group list was missing: the money pooled
        // fine, and choosing what to spend it on happened in the group chat.
        $organiser = User::factory()->create();
        $list = $this->groupList($organiser);
        $item = WishlistItem::factory()->create(['wishlist_id' => $list->id]);

        $member = User::factory()->create();

        $this->actingAs($member)
            ->post("/be-nl/l/{$list->share_token}/vote/{$item->id}")
            ->assertRedirect();

        $this->assertSame(1, ListItemVote::query()->count());

        $this->actingAs($member)
            ->delete("/be-nl/l/{$list->share_token}/vote/{$item->id}")
            ->assertRedirect();

        $this->assertSame(0, ListItemVote::query()->count());
    }

    #[Test]
    public function an_anonymous_member_can_vote(): void
    {
        /*
         * The reason `list_item_votes` mirrors `gift_pledges` rather than
         * hanging off `users`. Somebody joins an office group by link and never
         * signs up; requiring an account to vote is how most of the group does
         * not, and the organiser goes back to the spreadsheet.
         */
        $organiser = User::factory()->create();
        $list = $this->groupList($organiser);
        $item = WishlistItem::factory()->create(['wishlist_id' => $list->id]);
        $identity = AnonymousIdentity::create(['last_seen_at' => now()]);

        $this->withCookie(TrackAnonymousIdentity::COOKIE, (string) $identity->getKey())
            ->post("/be-nl/l/{$list->share_token}/vote/{$item->id}")
            ->assertRedirect();

        $this->assertSame(1, ListItemVote::query()->count());
    }

    #[Test]
    public function one_vote_per_person_per_item_even_when_the_writes_race(): void
    {
        /*
         * The partial unique index is the thing under test, and a sequential
         * double-post would not test it — `create()` twice in a row is caught
         * by anything. Two inserts around one another are what a phone does
         * when a tap lands before the first request has come back, and an
         * `updateOrCreate` (a read-then-write) lets both win.
         *
         * Written against the database rather than the endpoint so the race is
         * real rather than simulated.
         */
        $organiser = User::factory()->create();
        $list = $this->groupList($organiser);
        $item = WishlistItem::factory()->create(['wishlist_id' => $list->id]);
        $member = User::factory()->create();

        ListItemVote::create(['item_id' => $item->id, 'user_id' => $member->id, 'anon_id' => null]);

        $this->expectException(QueryException::class);

        ListItemVote::create(['item_id' => $item->id, 'user_id' => $member->id, 'anon_id' => null]);
    }

    #[Test]
    public function pressing_vote_twice_is_not_an_error_to_a_person(): void
    {
        // The index decides; the endpoint swallows its complaint, because the
        // state the person asked for is the state they get either way.
        $organiser = User::factory()->create();
        $list = $this->groupList($organiser);
        $item = WishlistItem::factory()->create(['wishlist_id' => $list->id]);
        $member = User::factory()->create();

        foreach ([1, 2] as $ignored) {
            $this->actingAs($member)
                ->post("/be-nl/l/{$list->share_token}/vote/{$item->id}")
                ->assertRedirect();
        }

        $this->assertSame(1, ListItemVote::query()->count());
    }

    #[Test]
    public function nothing_but_a_group_list_can_be_voted_on(): void
    {
        /*
         * A wish list is not a poll and a `for_someone` list is one person's
         * research. Posted directly rather than checked on the page — hiding a
         * button stops nobody hand-building the request.
         */
        $owner = User::factory()->create();
        $list = Wishlist::factory()->create([
            'owner_user_id' => $owner->id,
            'kind' => ListKind::Mine,
            'market' => Market::BeNl,
            'visibility' => ListVisibility::Link,
        ]);
        $item = WishlistItem::factory()->create(['wishlist_id' => $list->id]);

        $this->actingAs(User::factory()->create())
            ->post("/be-nl/l/{$list->share_token}/vote/{$item->id}")
            ->assertForbidden();

        $this->assertSame(0, ListItemVote::query()->count());
    }

    #[Test]
    public function the_shortlist_is_ordered_by_its_tally(): void
    {
        /*
         * A shortlist that does not visibly rank is a list, and the ordering is
         * half of what stops a visitor reading five candidates as five
         * presents.
         */
        $organiser = User::factory()->create();
        $list = $this->groupList($organiser);

        $quiet = WishlistItem::factory()->create(['wishlist_id' => $list->id, 'snapshot_title' => 'Quiet']);
        $popular = WishlistItem::factory()->create(['wishlist_id' => $list->id, 'snapshot_title' => 'Popular']);

        foreach ([User::factory()->create(), User::factory()->create()] as $voter) {
            ListItemVote::create(['item_id' => $popular->id, 'user_id' => $voter->id, 'anon_id' => null]);
        }

        $this->actingAs($organiser)
            ->get("/be-nl/l/{$list->share_token}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('items.0.title', 'Popular')
                ->where('items.0.votes', 2)
                ->where('items.1.title', 'Quiet')
                ->where('items.1.votes', 0));

        unset($quiet);
    }

    #[Test]
    public function a_wish_list_carries_no_vote_keys_at_all(): void
    {
        // Absent, not zero — the same discipline as `claimed` and
        // `contributions`. A `votes: 0` on a wish list is a key somebody later
        // renders, and the page reads the key's presence as "this is a
        // candidate".
        $list = Wishlist::factory()->create([
            'owner_user_id' => User::factory()->create()->id,
            'kind' => ListKind::Mine,
            'market' => Market::BeNl,
            'visibility' => ListVisibility::Link,
        ]);
        WishlistItem::factory()->create(['wishlist_id' => $list->id]);

        $this->get("/be-nl/l/{$list->share_token}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page->missing('items.0.votes'));
    }

    // --- Contributions: the write gate --------------------------------------

    #[Test]
    public function a_group_list_can_be_contributed_to(): void
    {
        /*
         * The gate used to be `allowsClaiming()`, which is mine-only — so a
         * list for a third person structurally could not carry contributions.
         *
         * The route names no item, and that is the second half of the same
         * idea: a group list is ONE present, so the money is towards the list
         * and the items under it are candidates nobody has chosen between yet.
         * Pledging against one would be a bet, and most of those bets would end
         * up attached to something nobody buys.
         */
        $organiser = User::factory()->create();
        $list = $this->groupList($organiser);
        $item = WishlistItem::factory()->create(['wishlist_id' => $list->id]);

        // Euros on the wire for this one endpoint, which has validated and
        // multiplied them since it shipped; `Pledge.tsx` normalises the comma
        // half our markets type before it gets here.
        $this->actingAs(User::factory()->create())
            ->post("/be-nl/l/{$list->share_token}/pledge", [
                'amount' => '25.50',
                'display_name' => 'Bob',
            ])
            ->assertRedirect();

        // Cents in the column, per invariant #7.
        $this->assertSame(2550, GiftPledge::query()->firstOrFail()->amount);
    }

    #[Test]
    public function the_organiser_of_a_group_list_may_contribute_to_it(): void
    {
        // They front the money and collect afterwards; refusing them would lock
        // the one person doing the organising out of the pool.
        $organiser = User::factory()->create();
        $list = $this->groupList($organiser);
        $item = WishlistItem::factory()->create(['wishlist_id' => $list->id]);

        $this->actingAs($organiser)
            ->post("/be-nl/l/{$list->share_token}/pledge", [
                'amount' => 40,
                'display_name' => 'Ann',
            ])
            ->assertRedirect();

        $this->assertSame(4000, GiftPledge::query()->firstOrFail()->amount);
    }

    #[Test]
    public function an_anonymous_visitor_can_contribute_and_take_it_back(): void
    {
        $organiser = User::factory()->create();
        $list = $this->groupList($organiser);
        $item = WishlistItem::factory()->create(['wishlist_id' => $list->id]);
        $identity = AnonymousIdentity::create(['last_seen_at' => now()]);

        $this->withCookie(TrackAnonymousIdentity::COOKIE, (string) $identity->getKey())
            ->post("/be-nl/l/{$list->share_token}/pledge", [
                'amount' => 10,
                'display_name' => 'Someone',
            ]);

        $this->assertSame(1, GiftPledge::query()->count());

        $this->withCookie(TrackAnonymousIdentity::COOKIE, (string) $identity->getKey())
            ->delete("/be-nl/l/{$list->share_token}/pledge");

        $this->assertSame(0, GiftPledge::query()->count());
    }

    // --- Contributions: the read path, which is the privacy rule -------------

    #[Test]
    public function the_organiser_of_a_group_list_sees_who_put_in_what(): void
    {
        $organiser = User::factory()->create();
        $list = $this->groupList($organiser);
        $item = WishlistItem::factory()->create(['wishlist_id' => $list->id]);

        foreach (['Bob' => 25, 'Cara' => 30] as $name => $amount) {
            $this->actingAs(User::factory()->create())
                ->post("/be-nl/l/{$list->share_token}/pledge", [
                    'amount' => $amount,
                    'display_name' => $name,
                ]);
        }

        $response = $this->actingAs($organiser)->get("/be-nl/lists/{$list->id}")->assertOk();

        $contributions = $this->props($response)['pot'];

        $this->assertSame(5500, $contributions['total']);
        $this->assertSame(2, $contributions['count']);
        $this->assertSame(
            ['Cara', 'Bob'],
            array_column($contributions['breakdown'], 'name'),
        );
    }

    #[Test]
    public function a_member_of_a_group_list_never_sees_another_members_amount(): void
    {
        /*
         * Everyone sees the total and their own share. A visible ladder of who
         * put in what is social pressure on whoever put in least, so the
         * breakdown belongs to the organiser and to nobody else.
         */
        $organiser = User::factory()->create();
        $list = $this->groupList($organiser);
        $item = WishlistItem::factory()->create(['wishlist_id' => $list->id]);

        $this->actingAs(User::factory()->create())
            ->post("/be-nl/l/{$list->share_token}/pledge", [
                'amount' => 25,
                'display_name' => 'Bob',
            ]);

        auth()->logout();

        $member = User::factory()->create();

        $this->actingAs($member)->post("/be-nl/l/{$list->share_token}/pledge", [
            'amount' => 30,
            'display_name' => 'Cara',
        ]);

        $response = $this->actingAs($member)->get("/be-nl/l/{$list->share_token}")->assertOk();
        $props = $this->props($response);
        $contributions = $props['pot'];

        // The pool and my own share: both coordination.
        $this->assertSame(5500, $contributions['total']);
        $this->assertSame(3000, $contributions['mine']);
        $this->assertArrayNotHasKey('breakdown', $contributions);

        // And nowhere else in the payload either.
        $this->assertStringNotContainsString('Bob', json_encode($props));
    }

    #[Test]
    public function a_collaborator_on_a_group_list_does_not_get_the_breakdown(): void
    {
        /*
         * A collaborator reaches the organiser's own page through
         * `ListAccess::scope()`. They are a member of the pool rather than the
         * person collecting it, so asking only "is this a group list?" would
         * hand them the ladder.
         */
        $organiser = User::factory()->create();
        $list = $this->groupList($organiser);
        $item = WishlistItem::factory()->create(['wishlist_id' => $list->id]);
        $mate = User::factory()->create();

        WishlistCollaborator::create([
            'wishlist_id' => $list->id,
            'user_id' => $mate->id,
            'role' => 'editor',
        ]);

        $this->actingAs(User::factory()->create())
            ->post("/be-nl/l/{$list->share_token}/pledge", [
                'amount' => 25,
                'display_name' => 'Bob',
            ]);

        $response = $this->actingAs($mate)->get("/be-nl/lists/{$list->id}")->assertOk();
        $props = $this->props($response);

        $this->assertArrayNotHasKey('breakdown', $props['pot']);
        $this->assertStringNotContainsString('Bob', json_encode($props));
    }

    #[Test]
    public function the_owner_of_a_wish_list_never_sees_contributions(): void
    {
        /*
         * Invariant #4, on both surfaces that render an owner's own list. The
         * assertion is that the KEY is absent — a `contributions: null` on every
         * item is a channel that goes live the day somebody tidies the null
         * away.
         */
        $owner = User::factory()->create();

        $list = Wishlist::factory()->create([
            'owner_user_id' => $owner->id,
            'kind' => ListKind::Mine,
            'market' => Market::BeNl,
            'visibility' => ListVisibility::Link,
        ]);

        $item = WishlistItem::factory()->create(['wishlist_id' => $list->id]);

        $this->actingAs(User::factory()->create())
            ->post("/be-nl/l/{$list->share_token}/pledge/{$item->id}", [
                'amount' => 25,
                'display_name' => 'Bob',
            ]);

        auth()->logout();

        foreach (["/be-nl/lists/{$list->id}", "/be-nl/l/{$list->share_token}"] as $url) {
            $response = $this->actingAs($owner)->get($url)->assertOk();
            $props = $this->props($response);

            $this->assertArrayNotHasKey('contributions', $props['items'][0], "leaked on {$url}");
            $this->assertStringNotContainsString('Bob', json_encode($props), "leaked on {$url}");
        }
    }

    #[Test]
    public function a_visitor_to_a_wish_list_sees_the_pool_but_not_who_is_in_it(): void
    {
        // A wish list still pools money — somebody claims the thing and the
        // others chip in — and its owner is still told none of it.
        $owner = User::factory()->create();

        $list = Wishlist::factory()->create([
            'owner_user_id' => $owner->id,
            'kind' => ListKind::Mine,
            'market' => Market::BeNl,
            'visibility' => ListVisibility::Link,
        ]);

        $item = WishlistItem::factory()->create(['wishlist_id' => $list->id]);

        $this->actingAs(User::factory()->create())
            ->post("/be-nl/l/{$list->share_token}/pledge/{$item->id}", [
                'amount' => 25,
                'display_name' => 'Bob',
            ]);

        auth()->logout();

        $response = $this->get("/be-nl/l/{$list->share_token}")->assertOk();
        $contributions = $this->props($response)['items'][0]['contributions'];

        $this->assertSame(2500, $contributions['total']);
        $this->assertNull($contributions['mine']);
        $this->assertArrayNotHasKey('breakdown', $contributions);
    }

    #[Test]
    public function a_research_list_carries_no_contributions_at_all(): void
    {
        // `for_someone` is one person's research: nothing to pool against, and
        // no key to render.
        $owner = User::factory()->create();

        $list = Wishlist::factory()->create([
            'owner_user_id' => $owner->id,
            'recipient_id' => Recipient::factory()->create(['owner_user_id' => $owner->id])->id,
            'kind' => ListKind::ForSomeone,
            'market' => Market::BeNl,
            'visibility' => ListVisibility::Link,
        ]);

        WishlistItem::factory()->create(['wishlist_id' => $list->id]);

        $response = $this->get("/be-nl/l/{$list->share_token}")->assertOk();

        $this->assertArrayNotHasKey('contributions', $this->props($response)['items'][0]);
    }
}
