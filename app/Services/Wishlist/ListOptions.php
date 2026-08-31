<?php

declare(strict_types=1);

namespace App\Services\Wishlist;

use App\Models\Wishlist;
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
        return self::query($owner)
            ->get()
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
}
