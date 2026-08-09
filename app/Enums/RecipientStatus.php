<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Whether the person described by a recipient row can speak for themselves yet.
 *
 * v1 modelled this and v2 lost it, which is why gifting here has only ever had
 * one participant. A recipient that is merely a note the giver keeps can never
 * contribute their own list, and "what they actually asked for" is the half of
 * gifting that matters most.
 */
enum RecipientStatus: string
{
    /** Described by the owner. Nobody has claimed the `/for/{token}` link. */
    case Stub = 'stub';

    /**
     * Bound to a real account. Their own lists can now appear alongside the
     * owner's research — see the two-lane gift page.
     */
    case Linked = 'linked';

    /** The owner describing themselves. */
    case Self = 'self';

    public function isLinked(): bool
    {
        return $this === self::Linked || $this === self::Self;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $s) => $s->value, self::cases());
    }
}
