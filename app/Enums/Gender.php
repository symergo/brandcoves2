<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Man or woman, an optional profile question beside the age (owner,
 * 2026-09-29), rather than folded into the relation.
 *
 * The relations were split by gender for a day (oma/opa, zoon/dochter, ...)
 * and that was reverted: it doubled every tag, since most products suit
 * both. Now the relation stays one value ("grandparent") and the gender is
 * an optional second answer, "Voor hem" or "Voor haar". A product carries a
 * `gender:` tag only when it is genuinely for one (a razor, a dress), so
 * nothing is tagged twice; an untagged product suits both and is never left
 * out. See docs/features/gift-gender.md.
 */
enum Gender: string
{
    case Male = 'male';
    case Female = 'female';

    /**
     * "Zeg ik liever niet" (owner, 2026-09-29): an answer, remembered so the
     * question does not come back as if it were open, and treated exactly as
     * no answer: nothing is left out, and a relation implies nothing over it.
     * Never a product tag ({@see tagValues()}).
     */
    case Unsaid = 'unsaid';

    /**
     * Every answer a form may send or a profile may hold.
     *
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $g) => $g->value, self::cases());
    }

    /**
     * What a product can be tagged with: man or woman, never "rather not say".
     *
     * @return list<string>
     */
    public static function tagValues(): array
    {
        return [self::Male->value, self::Female->value];
    }

    /** The gender this answer states, or null for "rather not say". */
    public function stated(): ?self
    {
        return $this === self::Unsaid ? null : $this;
    }

    /** The other one of a stated gender. */
    public function other(): self
    {
        return match ($this) {
            self::Male => self::Female,
            self::Female => self::Male,
            self::Unsaid => self::Unsaid,
        };
    }

    /** "Man", "Vrouw" or "Zeg ik liever niet": the answer as the chip says it. */
    public function label(): string
    {
        return (string) __("site.gift.genders.{$this->value}");
    }
}
