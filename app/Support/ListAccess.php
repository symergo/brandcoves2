<?php

declare(strict_types=1);

namespace App\Support;

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
     * Lists this person may open: their own, plus any they were invited to.
     *
     * @param  Builder<Wishlist>  $query
     * @return Builder<Wishlist>
     */
    public static function scope(Builder $query, Owner $owner): Builder
    {
        $user = $owner->user;

        if ($user === null) {
            // Anonymous visitors cannot be collaborators, so this collapses to
            // plain ownership — including the fail-closed empty result when
            // there is no identity at all.
            return $owner->scope($query);
        }

        return $query->where(fn (Builder $q) => $q
            ->where('owner_user_id', $user->id)
            ->orWhereExists(fn ($sub) => $sub
                ->selectRaw('1')
                ->from('wishlist_collaborators')
                ->whereColumn('wishlist_collaborators.wishlist_id', 'wishlists.id')
                ->where('wishlist_collaborators.user_id', $user->id)));
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
