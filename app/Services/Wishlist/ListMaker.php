<?php

declare(strict_types=1);

namespace App\Services\Wishlist;

use App\Enums\ListKind;
use App\Models\Recipient;
use App\Models\Wishlist;
use App\Support\CurrentMarket;
use App\Support\Owner;

/**
 * Making a list, from either of the two places that make one.
 *
 * `WishlistController::store()` (the form on My Lists) and
 * `WishlistItemController::createList()` (the save picker's "new list") both
 * resolved the recipient, minted a new person when one was named, and then
 * decided the kind — the same twenty lines, twice. That duplication is why one
 * of them would eventually diverge from the other, and the consequence of
 * diverging is a list whose kind disagrees with its recipient, which is the
 * exact ambiguity `ListKind` was introduced to remove.
 *
 * ## The recipient decides mine-vs-else; `together` adds one bit
 *
 * A list with no recipient is yours. A list with one is about somebody, and the
 * only remaining question is whether you are choosing for them alone or with
 * other people. So `together` is a **boolean**, never a client-supplied `kind`
 * string: a kind would have to be re-derived and re-checked against the
 * recipient anyway, and a boolean cannot contradict anything.
 *
 * A group list therefore always has a recipient — guaranteed here, and again by
 * a CHECK constraint, because the two mechanisms a group list carries
 * (contributions, and later voting) are meaningless without a third person for
 * the gift to be for. See docs/features/list-taxonomy.md.
 */
class ListMaker
{
    /**
     * @param  string|null  $recipientId  an existing person of this owner's
     * @param  string|null  $newRecipient  a name to mint a person from, when no id was given
     * @param  bool  $together  several people choosing one gift, rather than one person researching
     */
    public function make(
        Owner $owner,
        CurrentMarket $current,
        string $title,
        ?string $recipientId = null,
        ?string $newRecipient = null,
        bool $together = false,
    ): Wishlist {
        $recipientId = $this->resolveRecipient($owner, $recipientId, $newRecipient);

        return Wishlist::create([
            ...$owner->attributes(),
            'title' => $title,
            'market' => $current->get(),
            'recipient_id' => $recipientId,
            'kind' => $this->kind($recipientId, $together),
        ]);
    }

    /**
     * Which of the three this is.
     *
     * Ordered so the recipient is asked about first: `together` on a list for
     * nobody is a contradiction rather than a choice, and answering `Mine` is
     * the safe reading of it — a group list with no recipient would fail the
     * CHECK constraint on the way in, and a 500 on "make me a list" is a worse
     * answer than making the list they can actually have.
     */
    private function kind(?string $recipientId, bool $together): ListKind
    {
        return match (true) {
            $recipientId === null => ListKind::Mine,
            $together => ListKind::Group,
            default => ListKind::ForSomeone,
        };
    }

    /**
     * The person this list is about, minted if they are new.
     *
     * A named person is created here because "a list for my sister" is one
     * intention, and sending somebody to a different screen to create a contact
     * first is the step where they give up.
     */
    private function resolveRecipient(Owner $owner, ?string $recipientId, ?string $newRecipient): ?string
    {
        if (filled($recipientId)) {
            // A guessed uuid must not attach somebody else's person to my list.
            abort_unless(
                $owner->scope(Recipient::query())->whereKey($recipientId)->exists(),
                403,
            );

            return $recipientId;
        }

        if (filled($newRecipient)) {
            return Recipient::create([
                ...$owner->attributes(),
                'name' => $newRecipient,
            ])->id;
        }

        return null;
    }
}
