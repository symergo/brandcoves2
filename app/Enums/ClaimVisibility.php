<?php

declare(strict_types=1);

namespace App\Enums;

use App\Models\Wishlist;

/**
 * Who may see who claimed what, on a list about somebody else.
 *
 * ## One question only: do the buyers see *each other's* names?
 *
 * It briefly carried a third value, `hidden_from_owner`, and that was a value
 * of the wrong column. Whether the **owner** sees claims is a different
 * question with a different answer per person, and `wishlists.owner_sees_claims`
 * holds it — see {@see Wishlist::ownerSeesClaims()}. Conflating
 * them meant the third option changed meaning depending on the kind of list,
 * and made "show me claims, and let the others see each other's names"
 * impossible to say.
 *
 * What is left is one question about one audience: the people holding the link.
 * It applies to every claimable list — a wish list of your own included, where
 * two friends knowing which of them has the scarf helps them and tells you
 * nothing.
 */
enum ClaimVisibility: string
{
    /**
     * Everybody, the owner included, sees that something is taken. Never by
     * whom.
     *
     * The default, and deliberately the weakest disclosure that still
     * coordinates: knowing an item is spoken for is the whole of what stops two
     * people buying it, and a name adds nothing to that.
     */
    case Anonymous = 'anonymous';

    /**
     * Co-givers see who claimed what.
     *
     * For a set of people who already know each other — siblings buying for a
     * parent — where "Anna is getting the scarf" is more use than "somebody
     * is". A name is only ever stored while this is set, and never backfilled
     * onto claims made before it: nobody consented to being named then.
     */
    case Named = 'named';

    /** Is a claimer's name stored and shown? */
    public function namesClaimers(): bool
    {
        return $this === self::Named;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $v) => $v->value, self::cases());
    }
}
