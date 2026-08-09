<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Who last described this person's taste.
 *
 * A recipient row holds two different kinds of fact, owned by two different
 * people, and conflating them is how one of them gets destroyed:
 *
 * - **Giver context** — relationship, occasion, birthday, notes, budget. This
 *   is *my* situation, not theirs. Only the owner writes it, and the recipient
 *   must never even see it (`notes` is `$hidden` for that reason).
 * - **Taste** — interests, vibe, values, avoid. This belongs to the person
 *   being described. I may guess at it, but the moment they tell me, their
 *   answer is simply better evidence than mine.
 *
 * So a guess is `Suggested` and can be revised freely; once the person has
 * spoken it is `Self` and the owner's guesses no longer overwrite it. Storing
 * one flag rather than per-field provenance is deliberate: taste is answered as
 * a block, by one person, in one sitting.
 */
enum TasteSource: string
{
    /** Guessed by the person doing the shopping. */
    case Suggested = 'suggested';

    /** Answered by the person themselves. Outranks a guess. */
    case Self = 'self';

    public function outranks(self $other): bool
    {
        return $this === self::Self && $other === self::Suggested;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $s) => $s->value, self::cases());
    }
}
