<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Whether a gift is for him or for her, asked on its own (owner,
 * 2026-09-29) rather than folded into the relation.
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

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $g) => $g->value, self::cases());
    }

    public function other(): self
    {
        return $this === self::Male ? self::Female : self::Male;
    }

    /** "Voor hem" / "Voor haar": the answer as the chip says it. */
    public function label(): string
    {
        return (string) __("site.gift.genders.{$this->value}");
    }
}
