<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * What kind of article a guide row is.
 *
 * The distinction is not cosmetic — it decides whether a product shortlist is
 * required, and therefore whether the article can exist at all. See the
 * migration that added the column for why both live in one table.
 */
enum GuideKind: string
{
    /** A ranked shortlist. The products are the substance. */
    case Buying = 'buying';

    /** Prose about how to shop. No shortlist, and none is expected. */
    case Advice = 'advice';

    /**
     * The fewest items this kind can be published with.
     *
     * Three for a buying guide rather than GuideBuilder's five: five is the bar
     * for a guide *generated* from a mined topic, where a short list means the
     * catalogue could not fill it. A person writing "the two worth buying" has
     * made a judgement, and the number is the judgement.
     */
    public function minimumItems(): int
    {
        return $this === self::Buying ? 3 : 0;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $k) => $k->value, self::cases());
    }
}
