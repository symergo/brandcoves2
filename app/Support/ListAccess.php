<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\ListVisibility;
use App\Models\User;
use App\Models\Wishlist;
use App\Models\WishlistCollaborator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

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

        /*
         * Resolved in two steps rather than one `OR EXISTS … OR EXISTS …`
         * (speed wave 2, 2026-09-27). Postgres cannot use an index for an OR
         * of correlated EXISTS subqueries, so the one-statement form read every
         * row of `wishlists` and probed three tables per row, on every page
         * that asks "which lists may I open". The ids a person was let into are
         * a handful, each found through a `user_id` index; with them in hand
         * the outer query is `owner = ? OR id IN (…)`, two index lookups.
         *
         * The meaning is exactly the old one, and ListAccessScopeTest walks
         * every route through it.
         */
        ['direct' => $direct, 'bookmarked' => $bookmarked] = self::reachableIds($user);
        $key = $query->getModel()->getQualifiedKeyName();

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
            ->when($direct !== [], fn (Builder $q) => $q->orWhereIn($key, $direct))

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
            ->when($bookmarked !== [], fn (Builder $q) => $q->orWhere(fn (Builder $q) => $q
                ->where('visibility', '!=', ListVisibility::Private->value)
                ->whereIn($key, $bookmarked))));
    }

    /**
     * The lists somebody was let into, by route: `direct` (a collaborator row,
     * which holds whatever the list's visibility) and `bookmarked` (a followed
     * link or a share from a friend, which hold only while the list is shared).
     *
     * One statement, three `user_id` index lookups. Not memoised: a request
     * that records an open and then asks again must see the new row.
     *
     * @return array{direct: list<string>, bookmarked: list<string>}
     */
    public static function reachableIds(User $user): array
    {
        $rows = DB::table('wishlist_collaborators')
            ->selectRaw('wishlist_id, true as direct')
            ->where('user_id', $user->id)
            ->unionAll(DB::table('list_opens')
                ->selectRaw('wishlist_id, false as direct')
                ->where('user_id', $user->id))
            ->unionAll(DB::table('wishlist_shares')
                ->selectRaw('wishlist_id, false as direct')
                ->where('user_id', $user->id))
            ->get();

        $ids = fn (bool $direct): array => $rows
            ->filter(fn (object $row): bool => (bool) $row->direct === $direct)
            ->pluck('wishlist_id')
            ->map(fn ($id): string => (string) $id)
            ->unique()
            ->values()
            ->all();

        return ['direct' => $ids(true), 'bookmarked' => $ids(false)];
    }

    /**
     * May this person open this one list? The same answer as {@see scope()},
     * for a row already in hand: a caller that knows the id loads it by its
     * primary key and asks here, instead of running the whole scope to find
     * one row.
     */
    public static function allows(Wishlist $list, Owner $owner): bool
    {
        if ($owner->user === null) {
            return self::isOwner($list, $owner);
        }

        if ($list->owner_user_id === $owner->user->id) {
            return true;
        }

        $userId = $owner->user->id;

        if (WishlistCollaborator::query()->where('wishlist_id', $list->id)->where('user_id', $userId)->exists()) {
            return true;
        }

        // A null visibility fails `!= 'private'` in SQL too.
        if ($list->visibility === null || $list->visibility === ListVisibility::Private) {
            return false;
        }

        return DB::table('list_opens')->where('wishlist_id', $list->id)->where('user_id', $userId)->exists()
            || DB::table('wishlist_shares')->where('wishlist_id', $list->id)->where('user_id', $userId)->exists();
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

        /*
         * From the rows already loaded, when there are any. Both places that
         * load `collaborators` (the list page: all of them; My Coves: only my
         * own row) hold my row if I have one, so picking it out by user id is
         * the same answer without a query per list.
         */
        if ($list->relationLoaded('collaborators')) {
            $mine = $list->collaborators->first(fn (WishlistCollaborator $c): bool => $c->user_id === $owner->user->id);

            return $mine?->role->canEdit() ?? false;
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
