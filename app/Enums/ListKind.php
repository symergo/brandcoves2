<?php

declare(strict_types=1);

namespace App\Enums;

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

    public function allowsClaiming(): bool
    {
        return $this === self::Mine;
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
