<?php

declare(strict_types=1);

namespace App\Services\Wishlist;

use App\Enums\ListKind;
use App\Enums\ListVisibility;
use App\Models\Wishlist;
use App\Support\CurrentMarket;
use App\Support\Owner;

/**
 * "My wishlist" — the one list every owner has.
 *
 * A one-tap save has to land somewhere, and until now that somewhere was a list
 * called "Saved items" conjured on first use. That is a filing cabinet, not a
 * place: nobody thinks of it as theirs, nobody sends anyone a link to it, and
 * "where did my save go?" had no good answer.
 *
 * One row per owner is marked `is_default`, enforced by a partial unique index
 * rather than by convention — two defaults would make that question
 * unanswerable again, and this is the sort of thing a concurrent double-tap
 * creates.
 */
class DefaultList
{
    /**
     * The owner's standard list, created if this is their first.
     *
     * Adopts an existing `mine` list rather than making a second one, so people
     * who already have "Saved items" keep it — with its items — instead of
     * finding a new empty list beside it.
     */
    public function for(Owner $owner, CurrentMarket $current): Wishlist
    {
        // Signed in by the time this is reached: keeping a list requires an
        // account, so a default list for a cookie identity would be an orphan
        // nobody could reach from anywhere else.
        abort_if($owner->user === null, 403);

        $existing = $owner->scope(Wishlist::query())
            ->where('is_default', true)
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        /*
         * The oldest `mine` list they have, in any market.
         *
         * This was filtered to the current one, which is a filter a
         * market-independent list has no business carrying: somebody whose
         * only list was made on `nl-nl` and who arrived on `en` matched
         * nothing here and had a *second* default list created for them. Two
         * lists both called "My wishlist", both default, and which one a save
         * landed on depended on the prefix in the URL at the time.
         */
        $adopted = $owner->scope(Wishlist::query())
            ->where('kind', ListKind::Mine->value)
            ->whereNull('handed_over_at')
            ->oldest()
            ->first();

        if ($adopted !== null) {
            $adopted->update([
                'is_default' => true,
                /*
                 * Rename it, but only if it still carries a name we chose.
                 *
                 * Everybody who used the site before this feature has a list
                 * called "Saved items" or "Bewaard", and leaving it that way
                 * means the product calls it a wishlist everywhere except on
                 * the list itself. A title the person typed is theirs and is
                 * never touched.
                 */
                'title' => DefaultTitle::isOurs($adopted->title)
                    ? DefaultTitle::current()
                    : $adopted->title,
            ]);

            return $adopted;
        }

        return Wishlist::create([
            ...$owner->attributes(),
            'title' => DefaultTitle::current(),
            'market' => $current->get(),
            'kind' => ListKind::Mine,
            'is_default' => true,
            /*
             * Stated, not inherited from the column default.
             *
             * `create()` returns the model it built in memory, so a value only
             * Postgres knows about is null on the instance handed back — and
             * the caller reading `$list->visibility->isShareable()` on a list
             * it had just made got a null dereference on the first ever visit.
             */
            'visibility' => ListVisibility::Private,
        ]);
    }
}
