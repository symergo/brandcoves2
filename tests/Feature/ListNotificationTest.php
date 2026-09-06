<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ListKind;
use App\Enums\Market;
use App\Models\Notification;
use App\Models\Recipient;
use App\Models\User;
use App\Models\Wishlist;
use App\Models\WishlistItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * What lands in somebody's inbox when a list is used.
 *
 * The interesting half is what does **not**. A notification is the one channel
 * that goes and finds a person, so invariant #4 — the recipient of a wish list
 * never learns what has been claimed — is easier to break here than anywhere
 * else on the site, and breaking it is silent.
 */
class ListNotificationTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function a_wish_list_owner_is_never_told_that_something_was_claimed(): void
    {
        /*
         * The one that matters. Their friends are dividing up the shopping, and
         * an inbox row saying "something on your list has been spoken for" is
         * the surprise gone — no name and no item title needed, because the
         * moment it says anything at all they know.
         */
        [$owner, $list] = $this->list(ListKind::Mine);
        $item = WishlistItem::factory()->create(['wishlist_id' => $list->id]);

        $this->actingAs(User::factory()->create())
            ->post("/be-nl/l/{$list->share_token}/claim/{$item->id}")
            ->assertRedirect();

        $this->assertSame(0, Notification::query()->where('user_id', $owner->id)->count());
    }

    #[Test]
    public function asking_to_see_claims_does_not_open_the_inbox_either(): void
    {
        /*
         * `owner_sees_claims` is gone from the interface but the column remains
         * and `shouldHideClaimsFrom()` still reads it. If it is ever offered
         * again, the notification has to follow the same rule the page does —
         * this is what says so.
         */
        [$owner, $list] = $this->list(ListKind::Mine);
        $list->update(['owner_sees_claims' => true]);
        $item = WishlistItem::factory()->create(['wishlist_id' => $list->id]);

        $this->actingAs(User::factory()->create())
            ->post("/be-nl/l/{$list->share_token}/claim/{$item->id}");

        $this->assertSame(
            1,
            Notification::query()->where('user_id', $owner->id)->where('kind', 'list.claimed')->count(),
            'A wish list owner who has asked to see claims is told, exactly as the page tells them.',
        );
    }

    #[Test]
    public function the_organiser_of_a_gift_list_is_told(): void
    {
        // There the owner is a co-giver rather than the person being surprised,
        // and seeing what is covered is the whole point of the list.
        [$owner, $list] = $this->list(ListKind::ForSomeone);
        $item = WishlistItem::factory()->create(['wishlist_id' => $list->id]);

        $this->actingAs(User::factory()->create())
            ->post("/be-nl/l/{$list->share_token}/claim/{$item->id}");

        $this->assertSame(
            1,
            Notification::query()->where('user_id', $owner->id)->where('kind', 'list.claimed')->count(),
        );
    }

    #[Test]
    public function a_wish_list_owner_is_not_told_about_the_board_either(): void
    {
        /*
         * A board is claim state in prose, and `Board::visibleTo()` already
         * hides it from them on the page. The notification asks the same
         * question rather than a second copy of it.
         */
        [$owner, $list] = $this->list(ListKind::Mine);

        $this->actingAs(User::factory()->create())
            ->postJson("/be-nl/l/{$list->share_token}/messages", [
                'body' => 'I have the scarf, someone take the boots',
                'display_name' => 'Anna',
            ])->assertCreated();

        $this->assertSame(0, Notification::query()->where('user_id', $owner->id)->count());
    }

    #[Test]
    public function a_suggestion_reaches_the_owner(): void
    {
        // The one kind of activity that is useless unless they hear: a
        // suggestion waits for their decision.
        [$owner, $list] = $this->list(ListKind::Mine);

        $this->actingAs(User::factory()->create())
            ->post("/be-nl/l/{$list->share_token}/suggest", ['title' => 'A nice mug'])
            ->assertRedirect();

        $this->assertSame(
            1,
            Notification::query()->where('user_id', $owner->id)->where('kind', 'list.suggestion')->count(),
        );
    }

    #[Test]
    public function sharing_a_list_lands_in_the_inbox(): void
    {
        Mail::fake();

        $owner = User::factory()->create();
        $list = $this->listFor($owner, ListKind::Mine, 'Wedding');
        $friend = User::factory()->create();

        // A friendship, made the ordinary way.
        $doorway = $this->listFor($owner, ListKind::Mine, 'Doorway');
        $this->actingAs($friend)->get("/be-nl/l/{$doorway->share_token}")->assertOk();

        $this->actingAs($owner)
            ->post("/be-nl/lists/{$list->id}/share-with-friends", ['friend_ids' => [$friend->id]]);

        $notice = Notification::query()->where('user_id', $friend->id)->where('kind', 'list.shared')->first();

        $this->assertNotNull($notice);

        /*
         * One sentence, with the list name in quotes.
         *
         * It used to be interpolated bare, which read as "Iemand zette iets op
         * voor verjaardag samenleggen" — a title beginning with a preposition
         * welds onto the sentence and the reader cannot see where the name
         * begins. Nothing constrains what somebody calls a list, so the quotes
         * are what makes it legible whatever it is called.
         */
        $this->assertStringContainsString('“Wedding”', $notice->title);
        $this->assertNull($notice->body, 'The title is the whole sentence.');
    }

    #[Test]
    public function nobody_is_told_about_their_own_doing(): void
    {
        // An inbox reporting what you just pressed is noise on every surface.
        [$owner, $list] = $this->list(ListKind::ForSomeone);
        $item = WishlistItem::factory()->create(['wishlist_id' => $list->id]);

        $this->actingAs($owner)->post("/be-nl/l/{$list->share_token}/claim/{$item->id}");

        $this->assertSame(0, Notification::query()->where('user_id', $owner->id)->count());
    }

    /** @return array{0: User, 1: Wishlist} */
    private function list(ListKind $kind): array
    {
        $owner = User::factory()->create();

        return [$owner, $this->listFor($owner, $kind)];
    }

    private function listFor(User $owner, ListKind $kind, string $title = 'Theirs'): Wishlist
    {
        return Wishlist::create([
            'owner_user_id' => $owner->id,
            'title' => $title,
            'market' => Market::BeNl,
            'kind' => $kind,
            'visibility' => 'link',
            'recipient_id' => $kind === ListKind::Mine ? null : Recipient::create([
                'owner_user_id' => $owner->id,
                'name' => 'Anna',
            ])->id,
        ]);
    }
}
