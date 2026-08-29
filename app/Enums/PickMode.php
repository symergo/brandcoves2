<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * How much of a Cove the engine is allowed to choose.
 *
 * Curation and ranking are both legitimate ways to fill a page, and which one
 * is right is an editorial decision that changes per plan: a hand-built feature
 * day wants exactly what a person chose, an ordinary Tuesday with three good
 * ideas in it wants those three and a ranker for the rest.
 *
 * Making it a per-plan switch rather than a global setting is what stops the
 * choice from being made once, in config, by whoever shipped it.
 */
enum PickMode: string
{
    /**
     * The curated products lead; the engine tops the edition up to
     * `giftcoves.picks.per_day` from the themed and surprise lanes.
     */
    case Open = 'open';

    /**
     * The curated products *are* the edition, in the curator's order.
     *
     * The engine adds nothing, and the variety trim is skipped — reordering a
     * list somebody ordered by hand is precisely what they did not ask for.
     */
    case Locked = 'locked';

    public function allowsEngineFill(): bool
    {
        return $this === self::Open;
    }

    public function label(): string
    {
        return match ($this) {
            self::Open => 'open — curated first, engine fills the rest',
            self::Locked => 'locked — exactly the curated list, in order',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $m) => $m->value, self::cases());
    }
}
