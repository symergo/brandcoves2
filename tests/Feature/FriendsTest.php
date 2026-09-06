<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ListKind;
use App\Enums\ListVisibility;
use App\Enums\Market;
use App\Mail\ListInvitationMail;
use App\Models\FriendInvite;
use App\Models\Friendship;
use App\Models\Recipient;
use App\Models\User;
use App\Models\Wishlist;
use App\Models\WishlistItem;
use Illuminate\Auth\Events\Login;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The people you share lists with.
 *
 * Sharing is a link, which left both ends anonymous to each other: you claim
 * something off a friend's registry and neither of you ends up with any record
 * that the other exists. These tests hold the two ways that record now gets
 * made — following a link, and adding an address — and the three things it must
 * not become: a permission, an enumeration oracle, or a one-sided connection.
 */
class FriendsTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function following_a_link_and_signing_in_connects_the_two_people(): void
    {
        [$owner, $list] = $this->sharedList();

        // Opened signed out, which is the ordinary journey: the link arrives in
        // a message and the account comes after.
        $this->get("/be-nl/l/{$list->share_token}")->assertOk();

        $visitor = User::factory()->create();
        $this->actingAs($visitor);
        event(new Login('web', $visitor, false));

        $this->assertTrue($this->connected($owner, $visitor));
    }

    #[Test]
    public function a_visitor_who_is_already_signed_in_is_connected_on_the_read(): void
    {
        // Nothing left to wait for: the link has been followed and both ends
        // are accounts, so there is no sign-in to hang the connection on.
        [$owner, $list] = $this->sharedList();

        $visitor = User::factory()->create();
        $this->actingAs($visitor)->get("/be-nl/l/{$list->share_token}")->assertOk();

        $this->assertTrue($this->connected($owner, $visitor));
    }

    #[Test]
    public function an_owner_following_their_own_link_befriends_nobody(): void
    {
        // `link()` swallows the self case rather than tripping the CHECK
        // constraint: an owner checking what their share link looks like is the
        // ordinary thing to do, not an error.
        [$owner, $list] = $this->sharedList();

        $this->actingAs($owner)->get("/be-nl/l/{$list->share_token}")->assertOk();

        $this->assertSame(0, Friendship::query()->count());
    }

    #[Test]
    public function a_connection_is_symmetric_and_so_is_removing_it(): void
    {
        /*
         * The half that would be missing is the half that shows you on their
         * page, so a one-sided friendship reads as a bug on whichever side
         * lacks it — and a removal one person can still see after the other
         * ended it is the shape of thing people mean when they say a site kept
         * their data.
         */
        [$owner, $list] = $this->sharedList();
        $visitor = User::factory()->create();

        $this->actingAs($visitor)->get("/be-nl/l/{$list->share_token}");

        $this->assertSame(2, Friendship::query()->count());

        $this->actingAs($owner)->delete("/be-nl/friends/{$visitor->id}")->assertRedirect();

        $this->assertSame(0, Friendship::query()->count());
    }

    #[Test]
    public function adding_by_email_answers_the_same_whether_or_not_the_address_has_an_account(): void
    {
        /*
         * The disclosure this endpoint must not make.
         *
         * A truthful "no such account" would let somebody walk a mailing list
         * through this and learn who shops on a site that holds wish lists. So
         * both cases redirect with the same flash, and the one without an
         * account waits as an invite instead.
         */
        $inviter = User::factory()->create();
        $existing = User::factory()->create(['email' => 'anna@example.com']);

        $known = $this->actingAs($inviter)->post('/be-nl/friends', ['email' => 'anna@example.com']);
        $unknown = $this->actingAs($inviter)->post('/be-nl/friends', ['email' => 'nobody@example.com']);

        $known->assertRedirect()->assertSessionHas('success');
        $unknown->assertRedirect()->assertSessionHas('success');
        $this->assertSame($known->getSession()->get('success'), $unknown->getSession()->get('success'));

        $this->assertTrue($this->connected($inviter, $existing));
        $this->assertDatabaseHas('friend_invites', ['inviter_id' => $inviter->id, 'email' => 'nobody@example.com']);
    }

    #[Test]
    public function an_invite_is_applied_when_that_person_finally_signs_in(): void
    {
        $inviter = User::factory()->create();

        $this->actingAs($inviter)->post('/be-nl/friends', [
            'email' => 'Later@Example.com',
            'birthday' => '04-02',
        ])->assertRedirect();

        // Capitalised on the way in and lowercased on the way out: `users.email`
        // is case-sensitive in Postgres, so an invite that differed only in
        // capitals would sit unmatched forever beside the account it meant.
        $arrival = User::factory()->create(['email' => 'later@example.com']);
        $this->actingAs($arrival);
        event(new Login('web', $arrival, false));

        $this->assertTrue($this->connected($inviter, $arrival));
        $this->assertSame(0, FriendInvite::query()->count());

        // The date the inviter wrote down, on the inviter's own row only, and
        // with no year on it: what you record about somebody else is when to
        // buy them something, not how old they are.
        $mine = Friendship::query()
            ->where('user_id', $inviter->id)
            ->where('friend_id', $arrival->id)
            ->first();

        $this->assertSame(2, $mine->friend_birthday_day);
        $this->assertSame(4, $mine->friend_birthday_month);

        $this->assertNull(
            Friendship::query()
                ->where('user_id', $arrival->id)
                ->where('friend_id', $inviter->id)
                ->value('friend_birthday_day'),
        );
    }

    #[Test]
    public function a_birthday_you_wrote_down_never_becomes_their_answer(): void
    {
        /*
         * Two birthdays, and they are not the same fact. You may know your
         * brother's; he has not published it; that is your note, and a date
         * somebody guessed must not start appearing to everybody else as fact.
         */
        $inviter = User::factory()->create();
        $friend = User::factory()->create(['email' => 'sam@example.com', 'birthday' => null]);

        $this->actingAs($inviter)->post('/be-nl/friends', [
            'email' => 'sam@example.com',
            'birthday' => '12-01',
        ]);

        $this->assertNull($friend->fresh()->birthday);

        $this->actingAs($inviter)->get('/be-nl/friends')->assertInertia(fn ($page) => $page
            ->where('friends.0.birthday', '12-01')
            ->where('friends.0.birthdayIsMine', true));

        /*
         * And their own published date takes precedence over it — trimmed to
         * the day and the month on the way out. The year is real and theirs;
         * it is simply not this page's to show.
         */
        $friend->update(['birthday' => '1988-11-30']);

        $this->actingAs($inviter)->get('/be-nl/friends')->assertInertia(fn ($page) => $page
            ->where('friends.0.birthday', '11-30')
            ->where('friends.0.birthdayIsMine', false));
    }

    #[Test]
    public function a_year_cannot_be_recorded_about_somebody_else(): void
    {
        /*
         * The rule the column shape exists for. A year is somebody's age, and
         * the only person who may put one on this site is its owner, about
         * themselves, on their own settings. A full date offered here is not
         * quietly trimmed — it is refused, because accepting it would mean the
         * form and the store disagreed about what is being asked for.
         */
        $inviter = User::factory()->create();

        $this->actingAs($inviter)
            ->post('/be-nl/friends', ['email' => 'x@example.com', 'birthday' => '1988-12-01'])
            ->assertSessionHasErrors('birthday');
    }

    #[Test]
    public function switching_a_birthday_off_hides_it_from_friends(): void
    {
        $inviter = User::factory()->create();
        $friend = User::factory()->create(['email' => 'kim@example.com', 'birthday' => '1991-06-06']);

        $this->actingAs($inviter)->post('/be-nl/friends', ['email' => 'kim@example.com']);
        $friend->update(['friends_see_birthday' => false]);

        $this->actingAs($inviter)->get('/be-nl/friends')->assertInertia(
            fn ($page) => $page->where('friends.0.birthday', null),
        );
    }

    #[Test]
    public function the_friend_page_carries_no_claim_state(): void
    {
        /*
         * Invariant #4, on a surface where a helpful "2 of 8 spoken for" is
         * exactly what somebody would add next. A friend list is one join away
         * from telling a person's friend what their own list must not show
         * them, and the way that stays true is that items are never loaded.
         */
        [$owner, $list] = $this->sharedList();
        $item = WishlistItem::factory()->create(['wishlist_id' => $list->id]);

        $visitor = User::factory()->create();
        $this->actingAs($visitor)->get("/be-nl/l/{$list->share_token}")->assertOk();
        $this->actingAs($visitor)->post("/be-nl/l/{$list->share_token}/claim/{$item->id}");

        $props = $this->actingAs($owner)->get('/be-nl/friends')->assertOk()->viewData('page')['props'];
        $encoded = json_encode($props['friends']);

        foreach (['claimed', 'claims', 'progress', 'bought', 'taken'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $encoded);
        }
    }

    #[Test]
    public function the_page_needs_an_account(): void
    {
        // A friendship is between two accounts. There is nothing to show
        // somebody who is not one of them.
        $this->get('/be-nl/friends')->assertRedirect();
    }

    #[Test]
    public function a_list_reaches_a_friend_you_picked_and_nobody_else(): void
    {
        /*
         * The rule that replaced a switch.
         *
         * There was a `show_to_friends` boolean for a day meaning "everybody I
         * am connected to sees this". A friendship here is made by opening any
         * share link, so it published to a set the owner had never chosen and
         * could not see — one tap, and a list in front of people who had
         * accumulated by accident. A boolean cannot express consent to an
         * audience; a row per person can.
         */
        $owner = User::factory()->create();
        $list = $this->sharedListFor($owner, 'Wedding');

        $picked = User::factory()->create();
        $other = User::factory()->create();

        // Both are friends, through a different list entirely.
        $doorway = $this->sharedListFor($owner, 'Doorway');
        foreach ([$picked, $other] as $friend) {
            $this->actingAs($friend)->get("/be-nl/l/{$doorway->share_token}")->assertOk();
        }

        $this->actingAs($owner)
            // Silent on success: the chips come back ticked and green, which is
            // where the page says it. The row below is the assertion.
            ->post("/be-nl/lists/{$list->id}/share-with-friends", ['friend_ids' => [$picked->id]])
            ->assertRedirect();

        $this->assertDatabaseHas('wishlist_shares', [
            'wishlist_id' => $list->id,
            'user_id' => $picked->id,
        ]);

        $this->actingAs($picked)->get('/be-nl/friends')->assertInertia(
            fn ($page) => $page->where('friends.0.lists', fn ($lists) => str_contains(json_encode($lists), 'Wedding')),
        );

        // The other friend never learns it exists.
        $this->actingAs($other)->get('/be-nl/friends')->assertInertia(
            fn ($page) => $page->where('friends.0.lists', fn ($lists) => ! str_contains(json_encode($lists), 'Wedding')),
        );
    }

    #[Test]
    public function sharing_emails_the_people_you_picked_once(): void
    {
        /*
         * The email is a nudge, not the mechanism — the friends page shows the
         * list either way — but it is the half that reaches somebody who is not
         * looking. Once per person: sharing again is a state, not an event, and
         * a duplicate press is the commonest way to send two of the same
         * message.
         */
        Mail::fake();

        $owner = User::factory()->create();
        $list = $this->sharedListFor($owner, 'Wedding');
        $friend = User::factory()->create();

        $this->actingAs($friend)->get("/be-nl/l/{$list->share_token}")->assertOk();

        $this->actingAs($owner)
            ->post("/be-nl/lists/{$list->id}/share-with-friends", ['friend_ids' => [$friend->id]]);

        Mail::assertQueued(ListInvitationMail::class, 1);

        $this->actingAs($owner)
            ->post("/be-nl/lists/{$list->id}/share-with-friends", ['friend_ids' => [$friend->id]])
            ->assertSessionHas('error');

        Mail::assertQueued(ListInvitationMail::class, 1);
    }

    #[Test]
    public function you_cannot_share_a_list_with_somebody_who_is_not_your_friend(): void
    {
        /*
         * The check that stops this becoming a way to put an arbitrary list on
         * an arbitrary stranger's page, and to email them about it. Dropped in
         * silence rather than argued with: the picker only ever offers friends,
         * so an id that is not one arrived by hand, and a validation error here
         * would answer "is this person your friend" to whoever asked.
         */
        Mail::fake();

        $owner = User::factory()->create();
        $list = $this->sharedListFor($owner, 'Wedding');
        $stranger = User::factory()->create();

        $this->actingAs($owner)
            ->post("/be-nl/lists/{$list->id}/share-with-friends", ['friend_ids' => [$stranger->id]])
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertDatabaseCount('wishlist_shares', 0);
        Mail::assertNothingQueued();
    }

    #[Test]
    public function opening_a_link_is_the_other_invitation(): void
    {
        /*
         * Two ways onto a friends page and both are acts by the owner: they
         * picked you, or they sent you the link and you followed it.
         * `list_opens` has recorded the second since long before friends
         * existed, and it is what makes a group gift work with no rule of its
         * own — its audience was always "the people who were sent the link".
         */
        $owner = User::factory()->create();
        $group = $this->sharedListFor($owner, 'Leaving present', ListKind::Group);

        $invited = User::factory()->create();
        $this->actingAs($invited)->get("/be-nl/l/{$group->share_token}")->assertOk();

        $this->actingAs($invited)->get('/be-nl/friends')->assertInertia(
            fn ($page) => $page->where('friends.0.lists', fn ($lists) => count($lists) === 1
                && $lists[0]['title'] === 'Leaving present'),
        );
    }

    #[Test]
    public function un_sharing_takes_it_off_their_page_and_leaves_the_link_alone(): void
    {
        /*
         * The distinction every control in this area has to keep. A share row
         * makes a list findable; deleting it takes the list off a page. It does
         * not revoke a link, because that is `visibility`'s job and this must
         * not become a weaker second version of it.
         */
        Mail::fake();

        $owner = User::factory()->create();
        $list = $this->sharedListFor($owner, 'Wedding');
        $friend = User::factory()->create();

        $doorway = $this->sharedListFor($owner, 'Doorway');
        $this->actingAs($friend)->get("/be-nl/l/{$doorway->share_token}")->assertOk();

        $this->actingAs($owner)
            ->post("/be-nl/lists/{$list->id}/share-with-friends", ['friend_ids' => [$friend->id]]);

        $this->actingAs($owner)
            ->delete("/be-nl/lists/{$list->id}/share-with-friends/{$friend->id}")
            ->assertRedirect();

        $this->assertDatabaseCount('wishlist_shares', 0);

        $this->actingAs($friend)->get('/be-nl/friends')->assertInertia(
            fn ($page) => $page->where('friends.0.lists', fn ($lists) => ! str_contains(json_encode($lists), 'Wedding')),
        );

        // And the link still opens, which is the whole point of the distinction.
        $this->actingAs($friend)->get("/be-nl/l/{$list->share_token}")->assertOk();
    }

    #[Test]
    public function each_friend_row_says_what_they_see_of_yours(): void
    {
        /*
         * The half nobody thinks to check: "what does Anna actually see of
         * mine". Genuinely per person now, because sharing is per person.
         *
         * Your own lists link to your own page rather than to the share token —
         * following one should land you where you can edit it, and the visitor
         * view deliberately has none of the owner's controls on it.
         */
        Mail::fake();

        $owner = User::factory()->create();
        $shared = $this->sharedListFor($owner, 'Wedding');
        $doorway = $this->sharedListFor($owner, 'Doorway');

        $picked = User::factory()->create();
        $other = User::factory()->create();

        foreach ([$picked, $other] as $friend) {
            $this->actingAs($friend)->get("/be-nl/l/{$doorway->share_token}")->assertOk();
        }

        $this->actingAs($owner)
            ->post("/be-nl/lists/{$shared->id}/share-with-friends", ['friend_ids' => [$picked->id]]);

        $props = $this->actingAs($owner)->get('/be-nl/friends')->assertOk()->viewData('page')['props'];
        $byId = collect($props['friends'])->keyBy('id');

        $this->assertCount(2, $byId[$picked->id]['theySee'], 'Both the doorway and the shared list.');
        $this->assertCount(1, $byId[$other->id]['theySee'], 'Only the one they opened.');

        $this->assertStringContainsString(
            "lists/{$doorway->id}",
            collect($byId[$other->id]['theySee'])->first()['url'],
            'An owner following their own list should land on the page they can edit.',
        );
    }

    #[Test]
    public function a_list_you_only_opened_is_linked_to_the_page_that_can_claim(): void
    {
        /*
         * Reported from the browser, and pre-dating friends entirely.
         *
         * `ListAccess::scope()` unions `list_opens`, so every list you have
         * ever opened by link appears under Shared on My Lists — and every card
         * there took its url from `summarise()`, which builds `lists/{id}`: the
         * **owner's** page. So following a card landed a reader on the
         * organiser's view of somebody else's wish list, with the owner's tools
         * on it and no claim buttons anywhere, because claiming lives on the
         * shared page. The one thing they came to do was the one thing missing.
         */
        $owner = User::factory()->create();
        $list = $this->sharedListFor($owner, 'Mijn wenslijst');

        $reader = User::factory()->create();
        $this->actingAs($reader)->get("/be-nl/l/{$list->share_token}")->assertOk();

        $props = $this->actingAs($reader)
            ->get('/be-nl/lists?view=shared')
            ->assertOk()
            ->viewData('page')['props'];

        $card = collect($props['lists'])->firstWhere('title', 'Mijn wenslijst');

        $this->assertNotNull($card, 'A list you opened belongs under Shared.');
        $this->assertStringContainsString("l/{$list->share_token}", $card['url']);
        $this->assertStringNotContainsString("lists/{$list->id}", $card['url']);

        // And the owner's own card still goes to the page they can edit.
        $ownProps = $this->actingAs($owner)
            ->get('/be-nl/lists')
            ->assertOk()
            ->viewData('page')['props'];

        $this->assertStringContainsString(
            "lists/{$list->id}",
            collect($ownProps['lists'])->firstWhere('title', 'Mijn wenslijst')['url'],
        );
    }

    #[Test]
    public function a_list_shared_with_you_appears_on_my_lists_too(): void
    {
        /*
         * Reported from the browser: sharing put the list on the friends page
         * immediately and left it out of My Lists, so the one page called "my
         * lists" did not have the list somebody had just given them. It only
         * turned up once they clicked through the email, because
         * `ListAccess::scope()` unioned `list_opens` and not `wishlist_shares`.
         */
        Mail::fake();

        $owner = User::factory()->create();
        $list = $this->sharedListFor($owner, 'test lijstje', ListKind::ForSomeone);

        $friend = User::factory()->create();
        $doorway = $this->sharedListFor($owner, 'Doorway');
        $this->actingAs($friend)->get("/be-nl/l/{$doorway->share_token}")->assertOk();

        $this->actingAs($owner)
            ->post("/be-nl/lists/{$list->id}/share-with-friends", ['friend_ids' => [$friend->id]])
            ->assertRedirect();

        // Without ever opening the link.
        $props = $this->actingAs($friend)
            ->get('/be-nl/lists?view=shared')
            ->assertOk()
            ->viewData('page')['props'];

        $card = collect($props['lists'])->firstWhere('title', 'test lijstje');

        $this->assertNotNull($card, 'A list shared with you belongs under Shared.');
        $this->assertStringContainsString("l/{$list->share_token}", $card['url']);
    }

    #[Test]
    public function turning_sharing_off_takes_the_list_away_from_everybody(): void
    {
        /*
         * Reported from the browser: an owner set a list back to private and it
         * went on appearing under Shared on My Lists for the friend they had
         * shared it with.
         *
         * `ListAccess::scope()` never checked visibility. The `list_opens`
         * union had carried a comment claiming this exact protection since long
         * before "Share with friends" existed — "turning sharing off takes it
         * away from everybody who ever opened it" — and nothing enforced it, so
         * the new union inherited the hole and made it visible.
         *
         * Turning sharing off has to actually take it away, or it is only
         * hiding the link.
         */
        Mail::fake();

        $owner = User::factory()->create();
        $shared = $this->sharedListFor($owner, 'Wat David leuk zou vinden');
        $doorway = $this->sharedListFor($owner, 'Doorway');

        $friend = User::factory()->create();
        $this->actingAs($friend)->get("/be-nl/l/{$doorway->share_token}")->assertOk();
        $this->actingAs($friend)->get("/be-nl/l/{$shared->share_token}")->assertOk();

        $this->actingAs($owner)
            ->post("/be-nl/lists/{$shared->id}/share-with-friends", ['friend_ids' => [$friend->id]]);

        // Both routes in: shared with them by name, and opened by them.
        $this->actingAs($friend)->get('/be-nl/lists?view=shared')->assertOk()
            ->assertInertia(fn ($page) => $page->where('lists', fn ($lists) => str_contains(json_encode($lists), 'Wat David')));

        $shared->update(['visibility' => ListVisibility::Private]);

        $this->actingAs($friend)->get('/be-nl/lists?view=shared')->assertOk()
            ->assertInertia(fn ($page) => $page->where('lists', fn ($lists) => ! str_contains(json_encode($lists), 'Wat David')));

        // Off the friends page too, and the token stops resolving.
        $this->actingAs($friend)->get('/be-nl/friends')->assertInertia(
            fn ($page) => $page->where('friends.0.lists', fn ($lists) => ! str_contains(json_encode($lists), 'Wat David')),
        );

        $this->actingAs($friend)->get("/be-nl/l/{$shared->share_token}")->assertNotFound();

        // The owner still has their own list, which is the ordinary case.
        $this->actingAs($owner)->get("/be-nl/lists/{$shared->id}")->assertOk();
    }

    private function connected(User $a, User $b): bool
    {
        return Friendship::query()->where('user_id', $a->id)->where('friend_id', $b->id)->exists()
            && Friendship::query()->where('user_id', $b->id)->where('friend_id', $a->id)->exists();
    }

    /** @return array{0: User, 1: Wishlist} */
    private function sharedList(): array
    {
        $owner = User::factory()->create();

        return [$owner, $this->sharedListFor($owner, 'Wedding')];
    }

    private function sharedListFor(User $owner, string $title, ListKind $kind = ListKind::Mine): Wishlist
    {
        return Wishlist::create([
            'owner_user_id' => $owner->id,
            'title' => $title,
            'market' => Market::BeNl,
            'kind' => $kind,
            'visibility' => 'link',
            // A `mine` list is about its owner; the other two are about
            // somebody, and `wishlists_group_has_recipient` refuses a group
            // without one.
            'recipient_id' => $kind === ListKind::Mine ? null : Recipient::create([
                'owner_user_id' => $owner->id,
                'name' => 'Anna',
            ])->id,
        ]);
    }
}
