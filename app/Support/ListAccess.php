<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\ListVisibility;
use App\Models\Wishlist;
use App\Models\WishlistCollaborator;
use Illuminate\Database\Eloquent\Builder;

/**
 * Who may see and edit a list.
 *
 * `Owner::scope()` answers "did this person create it?", which was the whole
 * question while a list had exactly one owner. Co-givers make it two questions,
 * and the way that goes wrong is each controller answering the second one
 * slightly differently — so it is answered here, once.
 *
 * Collaboration is signed-in only, unlike ownership. An invitation is delivered
 * to an email address and accepted days later; a cookie identity has nowhere to
 * receive it and may well be gone by then. Same reasoning as alerts.
 */
final class ListAccess
{
    /**
     * Lists this person may open: their own, plus any they were invited to —
     * by an old collaborator row, by a link they followed, or by somebody
     * picking their name in "Share with friends".
     *
     * @param  Builder<Wishlist>  $query
     * @return Builder<Wishlist>
     */
    public static function scope(Builder $query, Owner $owner): Builder
    {
        $user = $owner->user;

        if ($user === null) {
            /*
             * An anonymous visitor can open a link and *is* remembered — the
             * bookmark takes a cookie identity — but this scope stays plain
             * ownership for them.
             *
             * Because it is also the scope that decides what somebody may
             * *edit*, and a cookie is not an identity worth widening a write
             * path on: it is shared by everyone using that browser and gone
             * when it is cleared. They keep reaching the list the way they were
             * always going to, through the link they were sent.
             */
            return $owner->scope($query);
        }

        return $query->where(fn (Builder $q) => $q
            // Your own, whatever state they are in. A private list of your own
            // is the ordinary case, not an exception.
            ->where('owner_user_id', $user->id)

            /*
             * Invited collaborators. **Legacy, and honoured rather than
             * created.** Sharing is a link now — see `ListOpen` — and nothing
             * writes this table any more, but real people were granted real
             * access through it before that and dropping the union would
             * silently revoke it.
             *
             * Deliberately **not** subject to the visibility test below. A
             * collaborator was invited by address rather than handed a link, so
             * their access does not depend on the list being link-shared: a
             * private list with collaborators on it is exactly what the feature
             * was, and `a_collaborator_can_open_a_private_list` says so by
             * name. Folding them in with the two bookmark routes broke that.
             */
            ->orWhereExists(fn ($sub) => $sub
                ->selectRaw('1')
                ->from('wishlist_collaborators')
                ->whereColumn('wishlist_collaborators.wishlist_id', 'wishlists.id')
                ->where('wishlist_collaborators.user_id', $user->id))

            /*
             * The two **bookmark** routes, and only while the list is still
             * shared.
             *
             * That condition was missing, and the bug it caused is the one the
             * `list_opens` comment had claimed to prevent since long before
             * "Share with friends" existed: an owner set a list back to private
             * and it went on appearing under Shared on My Lists for the friend
             * they had shared it with.
             *
             * Neither of these is a grant. One says "I followed your link", the
             * other "you sent it to me" — both are ways of finding a list
             * again, and both depend on the list still being findable at all.
             * Turning sharing off has to actually take it away, or it is only
             * hiding the link.
             *
             * Wrapped around both rather than repeated in each, so the next
             * bookmark route added here inherits it instead of forgetting it.
             */
            ->orWhere(fn (Builder $q) => $q
                ->where('visibility', '!=', ListVisibility::Private->value)
                ->where(fn (Builder $q) => $q
                    ->whereExists(fn ($sub) => $sub
                        ->selectRaw('1')
                        ->from('list_opens')
                        ->whereColumn('list_opens.wishlist_id', 'wishlists.id')
                        ->where('list_opens.user_id', $user->id))

                    ->orWhereExists(fn ($sub) => $sub
                        ->selectRaw('1')
                        ->from('wishlist_shares')
                        ->whereColumn('wishlist_shares.wishlist_id', 'wishlists.id')
                        ->where('wishlist_shares.user_id', $user->id)))));
    }

    /**
     * May this person add and remove items?
     *
     * The owner always may. A collaborator may only if they were invited as an
     * editor — a viewer is someone brought in to coordinate, not to curate.
     */
    public static function canEdit(Wishlist $list, Owner $owner): bool
    {
        if ($owner->user === null) {
            return $list->owner_anon_id !== null
                && $list->owner_anon_id === $owner->anonymous?->getKey();
        }

        if ($list->owner_user_id === $owner->user->id) {
            return true;
        }

        // `->value('role')` would return the cast enum on this model and a raw
        // string on another. Reading through the model keeps the cast honest.
        $collaborator = WishlistCollaborator::query()
            ->where('wishlist_id', $list->id)
            ->where('user_id', $owner->user->id)
            ->first();

        return $collaborator?->role->canEdit() ?? false;
    }

    public static function isOwner(Wishlist $list, Owner $owner): bool
    {
        return $owner->user !== null
            ? $list->owner_user_id === $owner->user->id
            : $list->owner_anon_id !== null && $list->owner_anon_id === $owner->anonymous?->getKey();
    }
}
