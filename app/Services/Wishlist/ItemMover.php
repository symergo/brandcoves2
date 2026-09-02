<?php

declare(strict_types=1);

namespace App\Services\Wishlist;

use App\Models\Wishlist;
use App\Models\WishlistItem;

/**
 * Copying an item onto another list.
 *
 * Two things wanted the same operation, from opposite directions:
 *
 * - **"Put this on another list too."** Somebody saved a scarf while shopping
 *   for one person and is now shopping for two.
 * - **"Add what they asked for to my list."** The recipient's own list is
 *   readable through the Ask panel, and every row on it is something the giver
 *   wants on the list they are actually working from — which, until now, they
 *   could read and not act on.
 *
 * ## Copy, never move
 *
 * Deliberately the only verb. A move is a copy and a removal, and removal
 * already exists on every row — so "move" would be a second way to do something
 * the page can already do, with a failure mode the copy does not have: pressed
 * on the wrong row it destroys the original, and on a shared list it would
 * delete a row from somebody *else's* wishlist because a third party pressed a
 * button.
 *
 * ## What does not come along
 *
 * `claimed_by_hash`, `claimed_by_name`, `claimed_at`, `marked_sent_at`,
 * `suggested_by_user_id`.
 *
 * This is the whole of the care this class needs. A claim is a fact about one
 * list — who agreed to buy this *there* — and carrying it to another would
 * announce, on a page its owner can see, that something has been bought. On a
 * wish list that is invariant 4 broken by a copy button. `suggested_by_user_id`
 * goes for the same reason in miniature: it names a person in a context they
 * did not agree to.
 *
 * A copy is therefore always a fresh, unclaimed row.
 */
class ItemMover
{
    /**
     * Put a copy of `$item` on `$to`, and return the row that is now there.
     *
     * Idempotent by product where it can be: a group already on the destination
     * is not added twice, because "copy" pressed twice is one intention and a
     * list carrying the same scarf three times is a list somebody has to tidy.
     * The existing row is returned rather than a failure — from the presser's
     * point of view the item is on the list, which is what they asked for.
     *
     * A hand-written item has no `group_id` to match on and is copied each
     * time. Two identical hand-written rows are indistinguishable, and refusing
     * the second would silently ignore a deliberate press.
     */
    public function copy(WishlistItem $item, Wishlist $to): WishlistItem
    {
        if ($item->group_id !== null) {
            $existing = $to->allItems()->where('group_id', $item->group_id)->first();

            if ($existing !== null) {
                return $existing;
            }
        }

        return WishlistItem::create([
            'wishlist_id' => $to->id,
            'group_id' => $item->group_id,
            'snapshot_title' => $item->snapshot_title,
            'snapshot_image_url' => $item->snapshot_image_url,
            'snapshot_price' => $item->snapshot_price,
            'snapshot_url' => $item->snapshot_url,
            'note' => $item->note,
            'source' => $item->source,
            'external_id' => $item->external_id,

            /*
             * Accepted on arrival.
             *
             * The approval queue exists for things a *stranger* added through a
             * share link. This is somebody putting a row on their own list, and
             * asking them to then approve their own press is a queue of one
             * addressed to the person who made it.
             */
            'accepted_at' => now(),
        ]);
    }
}
