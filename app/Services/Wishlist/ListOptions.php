<?php

declare(strict_types=1);

namespace App\Services\Wishlist;

use App\Models\Wishlist;
use App\Support\ListAccess;
use App\Support\Owner;
use Illuminate\Database\Eloquent\Builder;

/**
 * The lists a person may save a product into, in the order the picker shows them.
 *
 * One definition, two callers: the shared Inertia payload, which is how the
 * picker gets its rows without asking for them, and `GET /list-options`, which
 * still answers the same question over HTTP. They disagreeing about the order
 * would be a menu that rearranges itself depending on which of the two
 * happened to fill it.
 *
 * ## The order is fixed, and that is the whole point
 *
 * It was `latest('updated_at')`, and `ItemSaver` touches a list on every save —
 * so saving to a list moved it to the top of its section, and the next card's
 * picker had different rows under the same pixels. A menu that reorders itself
 * in response to being used is worst on exactly this control: the mistake it
 * causes is a save into the list one line off, which is the mistake the current
 * marker and the undo exist to recover from.
 *
 * The default list is pinned first because it is where a new account's saves
 * land. Everything under it is creation order, newest first, which nothing a
 * person does afterwards can change.
 */
final class ListOptions
{
    /** @return Builder<Wishlist> */
    public static function query(Owner $owner): Builder
    {
        return $owner->scope(Wishlist::query())
            /*
             * All of them, of every market. Somebody who set their lists up on
             * `nl-nl` and opened an `en` product page used to be shown an empty
             * picker and invited to start again — a list is not scoped to a
             * market, and the one they already had was one switch away and
             * invisible from here.
             */
            ->with('recipient')
            ->orderByDesc('is_default')
            ->latest('created_at');
    }

    /**
     * What the picker needs to draw a row, and nothing else.
     *
     * No item counts — no row shows one — and no membership: which list holds
     * *this* product is a fact about the product, and it rides with the rest of
     * them in `savedItems`.
     *
     * @return list<array{id: string, title: string, kind: string, recipient: string|null}>
     */
    public static function forPicker(Owner $owner): array
    {
        /*
         * The columns a row draws and nothing else. This runs on every
         * signed-in page (it rides in the shared props), and whole rows carried
         * descriptions, encrypted delivery addresses and a dozen settings the
         * picker never reads. It is a closure there, so an Inertia partial
         * reload that does not ask for `lists` never runs it at all.
         */
        return self::query($owner)
            ->with('recipient:id,name')
            ->get(['id', 'title', 'kind', 'is_default', 'created_at', 'recipient_id'])
            ->map(fn (Wishlist $list): array => [
                'id' => $list->id,
                'title' => $list->displayTitle(),
                // The distinction the picker is built around: a list for me and
                // a list about somebody else are different acts.
                'kind' => $list->kind->value,
                'recipient' => $list->recipient?->name,
            ])
            ->values()
            ->all();
    }

    /**
     * Where an item on `$except` can be copied to: every list this person may
     * write to, minus that one.
     *
     * One definition for the list page and the shared page, which each built
     * it by hand. `ListAccess::scope()` unions the lists I own with the ones I
     * have been let into; `canEdit()` is what says I may add to them, and it is
     * asked again at the endpoint because a payload decides a control and
     * nothing more.
     *
     * Kind and default ride along since the consistency review's round 3
     * (2026-09-27), when the copy menus took the save picker's rows: the
     * kind's icon beside each name, and my default list first, as the save
     * picker has it (`query()` above). Then by title. Only *my* default is
     * pinned: a list somebody let me into may be their default, which says
     * nothing about where I save.
     *
     * @return list<array{id: string, title: string, kind: string, isDefault: bool}>
     */
    public static function copyTargets(Owner $owner, Wishlist $except): array
    {
        $mine = $owner->attributes();

        return ListAccess::scope(Wishlist::query(), $owner)
            ->whereKeyNot($except->id)
            /*
             * My own collaborator row on each, and only mine, so `canEdit()`
             * below reads the role from it instead of asking once per list.
             * Never the whole roster: that is the owner's to see.
             */
            ->when($owner->user !== null, fn (Builder $q) => $q->with([
                'collaborators' => fn ($c) => $c->where('user_id', $owner->user->id),
            ]))
            ->orderBy('title')
            ->get(['id', 'title', 'kind', 'is_default', 'owner_user_id', 'owner_anon_id', 'recipient_id'])
            ->filter(fn (Wishlist $other): bool => ListAccess::canEdit($other, $owner))
            ->map(fn (Wishlist $other): array => [
                'id' => $other->id,
                'title' => $other->displayTitle(),
                'kind' => $other->kind->value,
                'isDefault' => (bool) $other->is_default
                    && $other->owner_user_id === $mine['owner_user_id']
                    && $other->owner_anon_id === $mine['owner_anon_id'],
            ])
            // Stable: the title order above stays under the pinned default.
            ->sortByDesc(fn (array $target): bool => $target['isDefault'])
            ->values()
            ->all();
    }
}
