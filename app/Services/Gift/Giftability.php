<?php

declare(strict_types=1);

namespace App\Services\Gift;

/**
 * The verdict on a catalogue row: can it be given, and is it worth showing.
 *
 * Two booleans rather than one, because they are two different questions and a
 * single flag answering both got the second one wrong. `giftable` is the gift
 * engine's filter and carries its price ceiling; `worthShowing` is what the
 * editorial surfaces gate on and does not. See docs/features/giftability.md.
 *
 * `reason` is stored alongside them so a rejection is explainable in admin
 * without re-running the whole classification pass — "why is this not in the
 * gift results" is otherwise an unanswerable question over 70,000 rows.
 */
final readonly class Giftability
{
    private function __construct(
        public bool $giftable,
        public string $reason,
        /** The matched phrase, for admin. Null when nothing in particular decided it. */
        public ?string $evidence = null,
        /** Fit to put on a page, whether or not you would wrap it. */
        public bool $worthShowing = false,
    ) {}

    public static function yes(string $reason = 'ok'): self
    {
        return new self(true, $reason, null, worthShowing: true);
    }

    /** Neither a gift nor worth a slot on a page. */
    public static function no(string $reason, ?string $evidence = null): self
    {
        return new self(false, $reason, $evidence);
    }

    /**
     * Not something to suggest as a present, but still a real object someone
     * would be glad to be shown.
     *
     * The €700 espresso machine: a joint present or a considered purchase, and
     * exactly the kind of thing a Cove is for.
     */
    public static function notAGiftButWorthShowing(string $reason): self
    {
        return new self(false, $reason, null, worthShowing: true);
    }
}
