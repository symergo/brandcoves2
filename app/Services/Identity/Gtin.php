<?php

declare(strict_types=1);

namespace App\Services\Identity;

/**
 * GTIN validation and normalisation.
 *
 * This is the authoritative path for deciding that two offers are the same
 * physical product, so it is deliberately strict. A wrong EAN merges unrelated
 * products into one offer set — a shopper sees "3 offers" for a thing that only
 * one shop actually sells, and the "cheapest" price belongs to something else
 * entirely. That is far worse than failing to merge, which merely costs us a
 * comparison we could have made.
 *
 * Everything here is pure and static so the rules can be unit-tested and
 * explained without touching a database.
 */
final class Gtin
{
    /**
     * Values feeds use to mean "no barcode".
     *
     * These are not hypothetical: affiliate feeds are full of them, and every
     * one would otherwise collapse thousands of unrelated products into a single
     * group.
     */
    private const PLACEHOLDERS = [
        '0', '00', '000', 'n/a', 'na', 'none', 'null', 'nil', '-', '--',
        'no ean', 'noean', 'geen', 'unknown', 'tbd', 'xxx',
    ];

    /**
     * Normalise a raw feed value to a GTIN-13, or null if it is not usable.
     *
     * Accepts GTIN-13/EAN-13, UPC-A (12), GTIN-8 (8) and ITF-14 (14).
     */
    public static function normalise(?string $raw): ?string
    {
        if ($raw === null) {
            return null;
        }

        $trimmed = strtolower(trim($raw));
        if ($trimmed === '' || in_array($trimmed, self::PLACEHOLDERS, true)) {
            return null;
        }

        // Feeds pad with spaces, hyphens and the occasional apostrophe.
        $digits = preg_replace('/\D/', '', $raw) ?? '';

        $candidate = match (strlen($digits)) {
            8 => self::fromGtin8($digits),
            12 => self::fromUpcA($digits),
            13 => $digits,
            14 => self::fromItf14($digits),
            default => null,
        };

        if ($candidate === null || ! self::hasValidCheckDigit($candidate)) {
            return null;
        }

        return self::isDegenerate($candidate) ? null : $candidate;
    }

    public static function isValid(?string $raw): bool
    {
        return self::normalise($raw) !== null;
    }

    /**
     * GS1 modulo-10 check digit.
     *
     * Weights alternate 3 and 1 from the right, excluding the check digit
     * itself. Works for any even-length GTIN, which is why it is written
     * position-relative rather than hard-coded for 13 digits.
     */
    public static function hasValidCheckDigit(string $digits): bool
    {
        $length = strlen($digits);
        if ($length < 8 || ! ctype_digit($digits)) {
            return false;
        }

        $sum = 0;
        // Walk right-to-left over the payload, skipping the check digit.
        for ($i = $length - 2, $position = 0; $i >= 0; $i--, $position++) {
            $weight = $position % 2 === 0 ? 3 : 1;
            $sum += ((int) $digits[$i]) * $weight;
        }

        $expected = (10 - ($sum % 10)) % 10;

        return $expected === (int) $digits[$length - 1];
    }

    /** UPC-A is a GTIN-13 with a leading zero. The check digit is unchanged. */
    private static function fromUpcA(string $digits): string
    {
        return '0'.$digits;
    }

    /** GTIN-8 zero-pads to 13; the check digit stays valid under the same weighting. */
    private static function fromGtin8(string $digits): ?string
    {
        return self::hasValidCheckDigit($digits) ? str_pad($digits, 13, '0', STR_PAD_LEFT) : null;
    }

    /**
     * ITF-14 is a packaging indicator + the GTIN-13 payload + its own check digit.
     *
     * Only indicator 0 means "the consumer unit itself". Indicators 1-8 mean a
     * carton or pallet of N units — genuinely a different product at a different
     * price, so merging it with the single unit would put a case of 24 next to
     * a single item as if they were competing offers. Indicator 9 is variable
     * measure. Both are rejected rather than unwrapped.
     */
    private static function fromItf14(string $digits): ?string
    {
        if ($digits[0] !== '0') {
            return null;
        }

        // Drop the indicator and the ITF-14 check digit, then recompute the
        // GTIN-13 check digit over the remaining 12-digit payload.
        $payload = substr($digits, 1, 12);

        return $payload.self::computeCheckDigit($payload);
    }

    /** Check digit for a payload that does not yet carry one. */
    public static function computeCheckDigit(string $payload): string
    {
        $sum = 0;
        for ($i = strlen($payload) - 1, $position = 0; $i >= 0; $i--, $position++) {
            $weight = $position % 2 === 0 ? 3 : 1;
            $sum += ((int) $payload[$i]) * $weight;
        }

        return (string) ((10 - ($sum % 10)) % 10);
    }

    /**
     * Structurally valid but meaningless barcodes.
     *
     * 0000000000000 passes the check digit. So does 2222222222222. Feeds emit
     * both as filler, and either would merge every product that carries it.
     */
    private static function isDegenerate(string $gtin): bool
    {
        // All the same digit.
        if (preg_match('/^(\d)\1+$/', $gtin) === 1) {
            return true;
        }

        // Leading zeros with a trivial tail: 0000000000017 and friends.
        if (str_starts_with($gtin, '000000000')) {
            return true;
        }

        // Restricted-distribution and coupon prefixes are shop-internal codes.
        // Two shops can legitimately assign the same one to different products,
        // which is precisely the merge we must not make.
        $prefix = substr($gtin, 0, 3);
        if (in_array($prefix, ['020', '021', '022', '023', '024', '025', '026', '027', '028', '029'], true)) {
            return true;
        }

        return in_array(substr($gtin, 0, 2), ['02', '04', '99'], true);
    }
}
