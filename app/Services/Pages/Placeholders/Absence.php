<?php

declare(strict_types=1);

namespace App\Services\Pages\Placeholders;

/**
 * When a placeholder counts as unavailable — and so hides the text that names it.
 *
 * This is the automatic half of the guard, and it exists because a sentence
 * mentioning a number is making a claim about it. ":reduced products are below
 * their median" on a page where nothing is reduced does not read as a gap; it
 * reads as "0 products", which is a false claim rendered in confident prose.
 *
 * Per function rather than one blanket rule, because "zero means missing" is
 * right for a count and wrong the day something has a legitimate zero. Keeping
 * it in the registry means the exception has somewhere to live and the rule
 * stays readable, instead of being a condition buried in the resolver.
 */
enum Absence: string
{
    /** Null or empty string. For text that can be blank but never meaningfully zero. */
    case Blank = 'blank';

    /** Null, empty string, or zero. The default, and right for every count and price. */
    case BlankOrZero = 'blank_or_zero';

    /** Never unavailable. For a value the region's own guard already promises. */
    case Never = 'never';

    public function hides(mixed $value): bool
    {
        if ($this === self::Never) {
            return false;
        }

        if ($value === null || $value === '' || $value === []) {
            return true;
        }

        return $this === self::BlankOrZero && ($value === 0 || $value === '0' || $value === 0.0);
    }
}
