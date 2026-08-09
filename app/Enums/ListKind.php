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

    public function allowsClaiming(): bool
    {
        return $this === self::Mine;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $k) => $k->value, self::cases());
    }
}
