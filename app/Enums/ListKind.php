<?php

declare(strict_types=1);

namespace App\Enums;

use App\Models\Wishlist;

/**
 * What a list is *for*, which is what decides whether it can be claimed from.
 *
 * This replaced a `is_gift_list` boolean that sat next to a nullable
 * `recipient_id` and could disagree with it. The two encoded overlapping
 * answers to the same question, so claiming ended up gated on *visibility*
 * instead — and any shared list became claimable, including someone's private
 * research about their own mother.
 */
enum ListKind: string
{
    /**
     * The owner's own list. Sharing it means "here is what I'd like", so
     * claiming is the entire point.
     */
    case Mine = 'mine';

    /**
     * A list *about* someone else, bound to a recipient. Sharing it means
     * "help me choose" or "don't double up" — co-giver coordination between
     * people who are all buying, not a registry to be claimed from.
     */
    case ForSomeone = 'for_someone';

    /**
     * Several people choosing one gift for a third person, together.
     *
     * Chosen when the list is made rather than derived from "a for_someone list
     * that happens to have co-givers". A derived kind moves a list between
     * sections when somebody is invited or removed, and a list that silently
     * changes address is one people lose. Choosing up front also guarantees the
     * recipient and the reason for contributions exist from the first save.
     */
    case Group = 'group';

    /**
     * Does saying "I'll get this" mean anything on this kind of list?
     *
     * **Was `Mine` only, and that was the wrong half of a right idea.** The
     * rule it enforced — never gate claiming on *visibility* — is still here
     * and still load-bearing: that bug made every shared list claimable,
     * including somebody's private research about their own mother. The
     * conclusion drawn from it was too strong. Claiming is coordination, and
     * co-giver coordination is precisely what a `for_someone` list is for; its
     * own docblock says "help me choose or don't double up", and refusing to
     * claim left that with no mechanism at all.
     *
     * What changes with it is who may *see* the claims, which inverts by kind
     * and then by a per-list setting. See {@see ClaimVisibility}.
     *
     * `Group` stays false, and is the reason this is not simply "any shared
     * list". A group list is **one** present bought by everybody, so there is
     * nothing to divide up and nothing to stop anyone duplicating. Its
     * mechanism is the pledge.
     *
     * Note this asks only about the *kind*. Whether there is anybody to
     * coordinate with is {@see Wishlist::hasCoGivers()}, and
     * `Wishlist::allowsClaiming()` is the two together.
     */
    public function allowsClaiming(): bool
    {
        return $this === self::Mine || $this === self::ForSomeone;
    }

    /**
     * Whether money may be pooled against items on this list.
     *
     * Deliberately **not** `allowsClaiming()`, which is what the pledge gate
     * used to ask. Claiming and contributing are different acts: claiming says
     * "I have got this one, nobody else take it", contributing says "here is my
     * share of one thing we are all buying". A group list allows the second and
     * not the first — there is nothing to claim, because the whole list is one
     * present.
     */
    public function allowsContributions(): bool
    {
        return $this === self::Mine || $this === self::Group;
    }

    /**
     * Whether members vote on which item to buy.
     *
     * Only a group list has a decision to make. A wish list is not a poll, and
     * a for_someone list is one person's research.
     */
    /**
     * Does an item from somebody holding the link go straight on the list?
     *
     * The alternative is the approval queue, and which one is right depends
     * entirely on **who the owner is to the list**.
     *
     * On a `mine` list a contribution is a *message to somebody about their own
     * wish list* — "I think you would like this". They decide what is on it,
     * and the accept/dismiss row is the whole point.
     *
     * On the other two the owner is a co-giver or an organiser, and everybody
     * on the list is working on research about a third person who never sees
     * any of it. Making each addition wait for the owner turns a shared
     * workspace into an inbox, and the person who has to empty it is the one
     * who asked for help in the first place.
     *
     * Note this covers a **catalogue** product only. Something typed in by hand
     * is free text from somebody holding a link that can be forwarded anywhere,
     * and that stays pending on every kind of list — see
     * `SuggestionController::store()`.
     */
    public function acceptsDirectAdditions(): bool
    {
        return $this !== self::Mine;
    }

    public function allowsVoting(): bool
    {
        return $this === self::Group;
    }

    /**
     * Whether the owner may see who contributed what.
     *
     * The inversion that makes group lists work, and the reason this is a
     * method rather than a constant. On a `mine` list the owner **is** the
     * person being surprised, so invariant #4 hides contributions from them
     * absolutely. On a `group` list the owner is the organiser and the
     * recipient is a third party who never sees the list at all — so there is
     * no surprise to protect from the owner, and the organiser is exactly who
     * needs the breakdown, because they front the money and collect afterwards.
     */
    /**
     * Is the money attached to the list, or to one item on it?
     *
     * A group list is **one present**, so the pot belongs to the list and the
     * shortlist under it is candidates for what to spend it on. Pledging
     * against a candidate would ask people to bet on an outcome the group has
     * not decided, and most of those pledges would end up attached to something
     * nobody buys.
     *
     * A `mine` list is the opposite: several people chipping in for the one
     * expensive thing on Anna's wishlist is a fact *about that thing*, and it
     * has to stay per item.
     *
     * Deliberately separate from {@see ownerSeesContributions()}, which is the
     * same set today. That one is about who may look at the money; this is
     * about where the money is attached. Asking one when you mean the other
     * works right up until they diverge, and then fails silently.
     */
    /**
     * Does the owner see claims when they have never said either way?
     *
     * A wish list hides: its owner is the person being surprised, and that is
     * what the list is for. A list about somebody else shows: the owner is a
     * co-giver organising the buying, and the recipient never opens the page,
     * so there is no surprise to protect from them.
     *
     * A **default**, not a rule. Since 2026-08-29 the owner may say otherwise
     * either way, and `Wishlist::ownerSeesClaims()` prefers what they said.
     */
    public function ownerSeesClaimsByDefault(): bool
    {
        return $this === self::ForSomeone;
    }

    public function poolsOnTheList(): bool
    {
        return $this === self::Group;
    }

    public function ownerSeesContributions(): bool
    {
        return $this === self::Group;
    }

    public function isForSomeoneElse(): bool
    {
        return $this === self::ForSomeone || $this === self::Group;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $k) => $k->value, self::cases());
    }
}
