<?php

declare(strict_types=1);

namespace App\Support;

/**
 * The code in a share link: short enough to read out, long enough to be a secret.
 *
 * A share token is not an identifier, it is a **credential** — holding it is the
 * whole of the permission to open a list. So the only question that matters is
 * how long a stranger guessing at random would take to hit *somebody's* list,
 * and note that it is somebody's, not yours: an attacker does not need a
 * particular list, so the odds scale with how many lists exist.
 *
 * ## Why ten
 *
 * Ten characters of this alphabet is 32^10 ≈ 1.1 × 10^15, about 50 bits. With a
 * hundred thousand lists in the table that is one hit per 10^10 guesses; against
 * the 60-per-minute limit on the route, centuries. Eight would be a year for one
 * attacker and an afternoon for a botnet, and five — which is where this started
 * — is about six minutes. These lists carry gift notes and, on a registry, a
 * home address.
 *
 * It replaced a uuid, which was 122 bits and 36 characters. Nothing needed that
 * much, and a link nobody can read out over the phone is its own kind of cost.
 *
 * ## The alphabet
 *
 * Crockford's base32: the digits and the lowercase letters, minus `i`, `l`, `o`
 * and `u`. The first three are excluded because they are indistinguishable from
 * `1` and `0` in most typefaces, on the one string somebody might copy by hand
 * off a phone screen; `u` because it keeps the set free of accidental words.
 * Exactly 32 symbols, so `random_int(0, 31)` maps onto it without the modulo
 * bias that `random_bytes() % strlen()` would introduce.
 *
 * ## Why it is not a uuid any more
 *
 * Beyond length: `wishlists.share_token` was a **native `uuid` column**, so a
 * stray path like `/l/suggest` reached Postgres as `where share_token =
 * 'suggest'` and raised `22P02: invalid input syntax for type uuid` — a 500
 * where the visitor should have had a 404. The route file carries three
 * hand-written `[0-9a-fA-F-]{36}` constraints to prevent exactly that. On a text
 * column a stray path is simply zero rows, and the constraint is a courtesy
 * rather than a load-bearing guard.
 */
final class ShareCode
{
    /** Crockford base32: no `i`, `l`, `o` or `u`. Exactly 32 symbols. */
    public const ALPHABET = '0123456789abcdefghjkmnpqrstvwxyz';

    public const LENGTH = 10;

    /**
     * A fresh code.
     *
     * `random_int` rather than `rand` or `Str::random`: this is a credential,
     * and the difference between a CSPRNG and a fast one is the whole of its
     * value. Uniqueness is left to the column's unique index and the caller's
     * retry — at 50 bits, a collision is rarer than the retry loop is worth
     * arguing about, but the index is what makes that a fact rather than a hope.
     */
    public static function make(): string
    {
        $code = '';

        for ($i = 0; $i < self::LENGTH; $i++) {
            $code .= self::ALPHABET[random_int(0, 31)];
        }

        return $code;
    }

    /**
     * The route constraint, so the pattern is written once.
     *
     * Deliberately generous about length: an old link, a hand-typed one, or a
     * token from a table that has not been converted yet should reach the
     * controller and get an honest 404 rather than a route-level miss that
     * looks like the page does not exist at all.
     */
    public static function pattern(): string
    {
        return '[0-9a-zA-Z-]{6,40}';
    }
}
