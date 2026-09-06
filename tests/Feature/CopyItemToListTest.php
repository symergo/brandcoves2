<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ListKind;
use App\Enums\ListVisibility;
use App\Enums\Market;
use App\Enums\Source;
use App\Models\ProductGroup;
use App\Models\Recipient;
use App\Models\User;
use App\Models\Wishlist;
use App\Models\WishlistItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Copying an item onto another list.
 *
 * One verb and two sources: a row on a list of mine, and a row on somebody
 * else's list read through the Ask panel. Most of this file is about the thing
 * that must not travel with the row — a claim — because carrying one would
 * announce on another list's page that something has been bought, which is
 * invariant 4 broken by a copy button.
 */
class CopyItemToListTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function a_hand_written_item_on_a_shared_list_can_be_copied_too(): void
    {
        /*
         * The gap the aligned control closed.
         *
         * The shared page drew `SaveToList`, which works from a `group_id`, so
         * an item somebody had **typed** offered nothing at all — while
         * `ItemTransferController::fromShared` had been able to copy one the
         * whole time. A note somebody typed is often the most copyable thing on
         * a list: it is the idea rather than the product.
         */
        $owner = User::factory()->create();
        $theirs = $this->list($owner, title: 'Theirs');
        $theirs->update(['visibility' => ListVisibility::Link]);

        $typed = WishlistItem::create([
            'wishlist_id' => $theirs->id,
            'source' => Source::Manual,
            'snapshot_title' => 'Iets zelfgemaakts',
            'accepted_at' => now(),
        ]);

        $visitor = User::factory()->create();
        $mine = $this->list($visitor, title: 'Mine');

        $this->actingAs($visitor)
            ->post("/be-nl/l/{$theirs->share_token}/items/{$typed->id}/copy", ['to' => $mine->id])
            ->assertRedirect();

        $this->assertSame(
            'Iets zelfgemaakts',
            $mine->items()->first()?->snapshot_title,
            'A hand-written item copies like any other.',
        );
    }

    #[Test]
    public function a_copy_can_name_a_new_list(): void
    {
        /*
         * What let the copy menu become the save picker.
         *
         * Without it the menu could only file into a list that already existed,
         * so somebody with none met a panel with nothing in it — and that,
         * rather than any deliberate choice, is why two different controls sat
         * in the same corner of two pages showing the same list.
         *
         * `ListMaker` makes it, the same service the save picker and the form
         * on My Lists use: the kind decision lives there, and a third copy of it
         * here is how a list whose kind disagrees with its recipient gets made.
         */
        $owner = User::factory()->create();
        $from = $this->list($owner, title: 'Mine');

        $item = WishlistItem::create([
            'wishlist_id' => $from->id,
            'source' => Source::Manual,
            'snapshot_title' => 'Iets zelfgemaakts',
            'accepted_at' => now(),
        ]);

        $this->actingAs($owner)
            ->post("/be-nl/lists/{$from->id}/items/{$item->id}/copy", ['new_list' => 'Kerst'])
            ->assertRedirect();

        $made = Wishlist::where('owner_user_id', $owner->id)->where('title', 'Kerst')->first();

        $this->assertNotNull($made, 'The named list is created.');
        $this->assertSame('Iets zelfgemaakts', $made->items()->first()?->snapshot_title);
    }

    #[Test]
    public function a_copy_still_needs_a_destination(): void
    {
        // One or the other, never neither: a copy with nowhere to go is a
        // request the menu cannot produce.
        $owner = User::factory()->create();
        $from = $this->list($owner, title: 'Mine');
        $item = WishlistItem::factory()->create(['wishlist_id' => $from->id]);

        $this->actingAs($owner)
            ->post("/be-nl/lists/{$from->id}/items/{$item->id}/copy", [])
            ->assertSessionHasErrors('to');
    }

    private function list(User $owner, ListKind $kind = ListKind::Mine, string $title = 'Mine'): Wishlist
    {
        return Wishlist::factory()->create([
            'owner_user_id' => $owner->id,
            'recipient_id' => $kind === ListKind::Mine ? null : Recipient::factory()->create([
                'owner_user_id' => $owner->id,
                'name' => 'Dad',
            ])->id,
            'kind' => $kind,
            'title' => $title,
            'market' => Market::BeNl,
            'visibility' => ListVisibility::Link,
        ]);
    }

    // ── Between my own lists ──────────────────────────────────────────────

    #[Test]
    public function an_item_can_be_copied_onto_another_list_of_mine(): void
    {
        $me = User::factory()->create();
        $from = $this->list($me, title: 'Ideas');
        $to = $this->list($me, title: 'Birthday');

        $item = WishlistItem::factory()->create([
            'wishlist_id' => $from->id,
            'snapshot_title' => 'Green kettle',
            'note' => 'the matte one',
        ]);

        $this->actingAs($me)
            ->post("/be-nl/lists/{$from->id}/items/{$item->id}/copy", ['to' => $to->id])
            ->assertRedirect();

        $copy = $to->items()->firstOrFail();

        $this->assertSame('Green kettle', $copy->snapshot_title);
        // The note travels: it is what the row says, and losing it is the
        // difference between a copy and a re-save of the product.
        $this->assertSame('the matte one', $copy->note);

        // The original is untouched. Copy is the only verb — removal already
        // exists on every row.
        $this->assertSame(1, $from->items()->count());
    }

    #[Test]
    public function a_claim_never_travels_with_a_copy(): void
    {
        /*
         * The rule this feature could break. A claim is a fact about one list —
         * who agreed to buy this *there* — and carrying it onto another list
         * would tell that list's owner something has been bought.
         */
        $me = User::factory()->create();
        $from = $this->list($me, ListKind::ForSomeone, 'For Dad');
        $to = $this->list($me, title: 'Mine');

        $item = WishlistItem::factory()->create([
            'wishlist_id' => $from->id,
            'claimed_by_hash' => str_repeat('a', 64),
            'claimed_by_name' => 'Bob',
            'claimed_at' => now(),
            'marked_sent_at' => now(),
        ]);

        $this->actingAs($me)
            ->post("/be-nl/lists/{$from->id}/items/{$item->id}/copy", ['to' => $to->id]);

        $copy = $to->items()->firstOrFail();

        $this->assertNull($copy->claimed_by_hash);
        $this->assertNull($copy->claimed_by_name);
        $this->assertNull($copy->claimed_at);
        $this->assertNull($copy->marked_sent_at);
    }

    #[Test]
    public function copying_the_same_product_twice_does_not_duplicate_it(): void
    {
        // "Copy" pressed twice is one intention, and a list carrying the same
        // scarf three times is a list somebody has to tidy.
        $me = User::factory()->create();
        $from = $this->list($me, title: 'Ideas');
        $to = $this->list($me, title: 'Birthday');

        $item = WishlistItem::factory()->create([
            'wishlist_id' => $from->id,
            'group_id' => ProductGroup::factory()->create()->id,
        ]);

        $this->actingAs($me)->post("/be-nl/lists/{$from->id}/items/{$item->id}/copy", ['to' => $to->id]);
        $this->actingAs($me)->post("/be-nl/lists/{$from->id}/items/{$item->id}/copy", ['to' => $to->id]);

        $this->assertSame(1, $to->items()->count());
    }

    #[Test]
    public function i_cannot_copy_onto_somebody_elses_list(): void
    {
        $me = User::factory()->create();
        $from = $this->list($me);
        $theirs = $this->list(User::factory()->create());

        $item = WishlistItem::factory()->create(['wishlist_id' => $from->id]);

        // A 404, not a 403: whether a uuid names a real list is not something
        // to confirm to somebody who cannot see it.
        $this->actingAs($me)
            ->post("/be-nl/lists/{$from->id}/items/{$item->id}/copy", ['to' => $theirs->id])
            ->assertNotFound();

        $this->assertSame(0, $theirs->items()->count());
    }

    #[Test]
    public function an_item_from_another_list_cannot_be_copied_through_mine(): void
    {
        $me = User::factory()->create();
        $mine = $this->list($me, title: 'Mine');
        $other = $this->list($me, title: 'Other');

        $item = WishlistItem::factory()->create(['wishlist_id' => $other->id]);

        // The row is none of this URL's business either way.
        $this->actingAs($me)
            ->post("/be-nl/lists/{$mine->id}/items/{$item->id}/copy", ['to' => $mine->id])
            ->assertNotFound();
    }

    // ── From the recipient's own list ─────────────────────────────────────

    #[Test]
    public function what_they_asked_for_can_be_added_to_the_list_i_asked_from(): void
    {
        /*
         * The payoff of the Ask panel. Their list was readable there and nothing
         * else — a giver could see "she wants the green kettle" and then had to
         * find it again on their own list by hand.
         */
        $me = User::factory()->create();
        $mine = $this->list($me, ListKind::ForSomeone, 'For Anna');

        $theirs = $this->list(User::factory()->create(), title: 'Anna’s wishlist');
        $item = WishlistItem::factory()->create([
            'wishlist_id' => $theirs->id,
            'snapshot_title' => 'Green kettle',
        ]);

        $this->actingAs($me)
            ->post("/be-nl/l/{$theirs->share_token}/items/{$item->id}/copy", ['to' => $mine->id])
            ->assertRedirect();

        $this->assertSame('Green kettle', $mine->items()->firstOrFail()->snapshot_title);

        // Their list is untouched: a giver has no business editing it.
        $this->assertSame(1, $theirs->items()->count());
    }

    #[Test]
    public function a_private_list_cannot_be_copied_from_by_its_token(): void
    {
        // The token is the permission, and a private list has withdrawn it.
        $me = User::factory()->create();
        $mine = $this->list($me);

        $theirs = $this->list(User::factory()->create());
        $theirs->update(['visibility' => ListVisibility::Private]);

        $item = WishlistItem::factory()->create(['wishlist_id' => $theirs->id]);

        $this->actingAs($me)
            ->post("/be-nl/l/{$theirs->share_token}/items/{$item->id}/copy", ['to' => $mine->id])
            ->assertNotFound();

        $this->assertSame(0, $mine->items()->count());
    }

    #[Test]
    public function a_copy_from_a_shared_list_arrives_unclaimed(): void
    {
        // Their own claim state is theirs. It must not appear on my list, where
        // I can see it.
        $me = User::factory()->create();
        $mine = $this->list($me);

        $theirs = $this->list(User::factory()->create());
        $item = WishlistItem::factory()->create([
            'wishlist_id' => $theirs->id,
            'claimed_by_hash' => str_repeat('b', 64),
            'claimed_at' => now(),
        ]);

        $this->actingAs($me)
            ->post("/be-nl/l/{$theirs->share_token}/items/{$item->id}/copy", ['to' => $mine->id]);

        $this->assertNull($mine->items()->firstOrFail()->claimed_by_hash);
    }
}
