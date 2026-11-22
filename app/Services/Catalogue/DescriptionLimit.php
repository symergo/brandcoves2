<?php

declare(strict_types=1);

namespace App\Services\Catalogue;

/**
 * How much of a shop's description an offer keeps: 1,000 characters (owner, 2026-11-22).
 *
 * Disk, not taste. On 2026-11-22 the `products` table was 22 GB of a 99 GB disk with
 * 19 GB free. The description is stored twice in effect: as text (bol 860 bytes an offer
 * on average) and inside `search_vector` (bol 1,301 bytes, most of it from the
 * description). bol's connector already cut at 2,000 characters and Awin's not at all,
 * and half of bol's descriptions were longer than 1,000. Cutting at 1,000 frees an
 * estimated 1.5 to 2 GB. What it costs: the product page shows at most 1,000 characters
 * of the shop's text instead of 1,800 (ProductDescription::LIMIT), and search no longer
 * matches a word that only appears late in a long description, the weakest of its four
 * weights.
 *
 * Cut on a word boundary: the text up to 1,001 characters, then back to the last space,
 * tab or line break, or a hard cut when there is none. `SQL` does the same in the
 * database for the descriptions stored before (`bc:trim-descriptions`). The two must
 * give identical text, or every feed run would see a changed description and rewrite
 * the offer; DescriptionLimitTest compares them.
 */
final class DescriptionLimit
{
    public const CHARACTERS = 1000;

    /**
     * The same cut, as a Postgres expression over `description`. Whitespace is spelled out
     * (space, tab, CR, LF) rather than `\s`, whose meaning differs between PHP and Postgres.
     */
    public const SQL = "CASE WHEN char_length(description) <= 1000 THEN description WHEN left(description, 1001) !~ '[ \\t\\r\\n]' THEN left(description, 1000) ELSE coalesce(nullif(regexp_replace(left(description, 1001), '[ \\t\\r\\n]+[^ \\t\\r\\n]*$', ''), ''), left(description, 1000)) END";

    public static function cut(?string $description): ?string
    {
        if ($description === null || mb_strlen($description) <= self::CHARACTERS) {
            return $description;
        }

        $head = mb_substr($description, 0, self::CHARACTERS + 1);

        // No space anywhere to cut at: a hard cut
        if (preg_match('/[ \t\r\n]/', $head) !== 1) {
            return mb_substr($description, 0, self::CHARACTERS);
        }

        $head = (string) preg_replace('/[ \t\r\n]+[^ \t\r\n]*$/u', '', $head);

        return $head !== '' ? $head : mb_substr($description, 0, self::CHARACTERS);
    }
}
