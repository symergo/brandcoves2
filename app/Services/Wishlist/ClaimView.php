<?php

declare(strict_types=1);

namespace App\Services\Wishlist;

use App\Models\Wishlist;
use App\Models\WishlistItem;
use App\Support\Owner;
use Illuminate\Support\Collection;

/**
 * What each person is allowed to know about who has claimed what.
 *
 * The sibling of {@see ContributionView}, and deliberately shaped like it: the
 * same question has different right answers depending on who is asking and what
 * kind of list it is, and the way that goes wrong is each surface working it
 * out again in a ternary.
 *
 * ## The table this class is
 *
 * | viewer | `mine` list | `for_someone`, anonymous | `for_someone`, named | `for_someone`, hidden |
 * |---|---|---|---|---|
 * | the owner | **nothing at all** | claimed | claimed + who | **nothing at all** |
 * | anyone else | claimed | claimed | claimed + who | claimed |
 *
 * A `group` list is absent from the table entirely: one present, nothing to
 * divide, and pledges rather than claims are its mechanism.
 *
 * ## The inversion, and why it is not a hole in invariant #4
 *
 * On a `mine` list the owner **is** the person being surprised, so they are
 * removed from the table completely — not a `false`, not a `null` at the item
 * level, no key at all. A `claimed: false` on every item is a channel that goes
 * live the moment one of them flips.
 *
 * On a `for_someone` list the recipient is a third party who never opens the
 * page. There is no surprise to protect *from the owner*, and the owner is the
 * person organising the buying — so hiding it leaves the one person
 * co-ordinating as the only one who cannot see what is covered.
 * `ClaimVisibility::HiddenFromOwner` puts them back behind the curtain when
 * they might end up receiving from the list, which is their choice to make.
 *
 * ## `claimedByMe` is not part of that table
 *
 * It answers "may I undo this?", which is a fact about the viewer's own claim
 * and discloses nothing about anybody else's. It is therefore computed for
 * every non-owner regardless of the setting, and stays false for the owner —
 * who cannot claim on their own list in the first place.
 */
class ClaimView
{
    /**
     * Claim payloads, keyed by item id.
     *
     * Items with nothing to say are **absent from the array**, so a caller
     * spreading `...$claims[$id] ?? []` emits no key at all rather than a null
     * that a later tidy-up turns into a live signal. Same discipline as
     * `ContributionView` and as `progress`.
     *
     * @param  Collection<int, WishlistItem>  $items
     * @return array<int, array<string, mixed>>
     */
    public function forItems(Wishlist $list, Collection $items, Owner $viewer, bool $hideClaims): array
    {
        if (! $list->allowsClaiming() || $hideClaims) {
            return [];
        }

        $hash = $this->hashFor($viewer);
        $names = $list->claim_visibility->namesClaimers();

        $out = [];

        foreach ($items as $item) {
            $mine = $hash !== null && $item->claimed_by_hash === $hash;

            $out[$item->id] = [
                'claimed' => $item->isClaimed(),
                'claimedByMe' => $mine,

                /*
                 * Who claimed it, when the list says names are shown.
                 *
                 * Absent — not null — on an anonymous list, so there is no key
                 * for a later change to start filling in. Null *within* a named
                 * list is different and meaningful: it is a claim made before
                 * the setting was turned on, and nobody consented to being
                 * named then. It renders as "spoken for", which is honest.
                 */
                ...$names ? ['claimedBy' => $item->claimed_by_name] : [],

                /*
                 * "I've bought it" is a fact about the claimer's own errand.
                 * Everybody else needs to know the item is spoken for and
                 * nothing more, so this is non-null only for the claim holder.
                 */
                'sent' => $mine ? $item->marked_sent_at !== null : null,
            ];
        }

        return $out;
    }

    /**
     * "3 of 11 spoken for", or nothing.
     *
     * Null rather than zero for anybody who may not see claims: a count **is**
     * claim state, and sending 0 to the owner of a wish list is just as fatal
     * as sending the truth, because the moment it stops being 0 they have
     * learnt something.
     *
     * @return array{claimed: int, total: int}|null
     */
    public function progress(Wishlist $list, bool $hideClaims): ?array
    {
        if (! $list->allowsClaiming() || $hideClaims) {
            return null;
        }

        return [
            'claimed' => $list->items()->whereNotNull('claimed_by_hash')->count(),
            'total' => $list->items()->count(),
        ];
    }

    /**
     * This viewer's claim hash, or null when they have no identity at all.
     *
     * The same salted hash the claim endpoint writes, so "is this mine?" is a
     * string comparison rather than a second notion of identity.
     */
    private function hashFor(Owner $viewer): ?string
    {
        $identity = $viewer->claimIdentity();

        return $identity === null ? null : WishlistItem::identityHash($identity);
    }
}
