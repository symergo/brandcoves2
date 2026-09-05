<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Who writes the prose for this Cove.
 *
 * One question, asked once, replacing the combination of empty and non-empty
 * fields that `EditionBuilder` used to infer it from in three places — and got
 * wrong in one of them, running the model over an article that was already
 * finished because its `blurb` happened to be blank.
 *
 * It is deliberately about the *writer* rather than about the words. "Does this
 * plan have prose yet" is a different question with a different answer during
 * the hours between a plan being marked authored and the author getting to it,
 * and conflating the two is what produced the bug this enum exists to end.
 */
enum PlanWriter: string
{
    /**
     * The model writes it at build time, from the prompt bank.
     *
     * The default, and what every plan did before this existed.
     */
    case Builder = 'builder';

    /**
     * Somebody else writes it, and the builder uses their words verbatim.
     *
     * A person in the panel, or Claude through the editorial API. The build
     * never calls `AiClient` for a plan in this state — which is why a Cove
     * written this way costs nothing against `giftcoves.ai.caps` and cannot be
     * refused by the daily cap. See docs/features/ai-invariant.md.
     */
    case Authored = 'authored';

    public function label(): string
    {
        return match ($this) {
            self::Builder => 'Written by the builder',
            self::Authored => 'Written by hand or by an author',
        };
    }

    /**
     * Does a build for this plan call a model?
     *
     * Asked rather than compared, because "authored" is about to mean more than
     * one case: a Cove whose prose came from the API and one typed into the
     * panel are the same to the builder and different to everybody else.
     */
    public function callsModel(): bool
    {
        return $this === self::Builder;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $w) => $w->value, self::cases());
    }
}
