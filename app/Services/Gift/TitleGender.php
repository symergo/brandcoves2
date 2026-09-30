<?php

declare(strict_types=1);

namespace App\Services\Gift;

use App\Enums\Gender;

/**
 * Who a product is for, when its title says so.
 *
 * Owner, 2026-09-30: "alles met dames in de titel is voor vrouw en alles met
 * heren is voor man". A shop that writes "Dames" or "Heren" in a title is
 * telling us the cut, and on production that covered about 11,700 products no
 * editor had tagged. Read at the moment it matters (SuggestionEngine's gender
 * filter, TasteCard for the games) rather than written into `gift_tags`, so a
 * product arriving tomorrow is covered too and an editor's tag stays theirs.
 *
 * The words, in the languages the feeds write (whole words only):
 *
 * - for her: dames, femme, femmes, women, woman
 * - for him: heren, homme, hommes, men
 *
 * "dames" and "heren" also at the start of a Dutch compound: damesfiets,
 * herenhorloge, damesparfum. The rest are whole words only.
 *
 * Left out after reading samples: "dame" alone (Notre-Dame, a vampire costume,
 * "der alten Dame") and "mens" (Dutch for a person: "Mens erger je niet"). A
 * title naming both ("heren- en damesschoenen") or "unisex" says nothing.
 */
final class TitleGender
{
    /** Postgres and PCRE both read these; `\m`-style boundaries are not shared, so the edges are spelled out. */
    private const FEMALE = '(^|[^a-z])(dames[a-z]*|femme|femmes|women|woman)([^a-z]|$)';

    private const MALE = '(^|[^a-z])(heren[a-z]*|homme|hommes|men)([^a-z]|$)';

    private const NEITHER = 'unisex';

    public static function of(?string $title): ?Gender
    {
        if ($title === null || $title === '') {
            return null;
        }

        $text = mb_strtolower($title);

        if (str_contains($text, self::NEITHER)) {
            return null;
        }

        $female = preg_match('/'.self::FEMALE.'/u', $text) === 1;
        $male = preg_match('/'.self::MALE.'/u', $text) === 1;

        return match (true) {
            $female && ! $male => Gender::Female,
            $male && ! $female => Gender::Male,
            default => null,
        };
    }

    /**
     * A Postgres condition (on `$column`) that is true when the title says the
     * product is for `$gender`, in the sense of {@see of()}. Case-insensitive
     * (`~*`); the patterns are constants, so interpolating them is safe.
     */
    public static function sql(Gender $gender, string $column = 'product_groups.title'): string
    {
        [$own, $other] = $gender === Gender::Female ? [self::FEMALE, self::MALE] : [self::MALE, self::FEMALE];

        return "({$column} ~* '{$own}' and not {$column} ~* '{$other}' and {$column} !~* '".self::NEITHER."')";
    }
}
