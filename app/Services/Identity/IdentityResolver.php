<?php

declare(strict_types=1);

namespace App\Services\Identity;

use App\Enums\IdentityKind;

/**
 * Decides which physical product an offer belongs to.
 *
 * Two paths, in strict priority order. Pure and static so the rules are
 * unit-testable and explainable in admin without re-running a pass.
 */
final class IdentityResolver
{
    /**
     * Noise merchants append to titles. Stripped before the fallback key is
     * built, because "Sony WH-1000XM5 (2024)" and "Sony WH-1000XM5 - nieuw
     * model" are one product listed by two shops.
     */
    private const NOISE = [
        // Model years and marketing suffixes.
        '/\b(19|20)\d{2}\s*(model|editie|edition|version)?\b/u',
        '/\b(nieuw|new|nouveau|nuevo)\s+(model|modèle|modelo)\b/u',
        // Shipping and stock claims that end up in titles.
        '/\b(gratis|free|kostenlose)\s+(verzending|shipping|levering|livraison)\b/u',
        '/\b(op\s+voorraad|in\s+stock|en\s+stock)\b/u',
        // Bare marketplace noise.
        '/\b(officiële|official|origineel|original|genuine)\b/u',
    ];

    public static function resolve(?string $ean, ?string $brand, string $title): ?Identity
    {
        $gtin = Gtin::normalise($ean);
        if ($gtin !== null) {
            return new Identity($gtin, IdentityKind::Ean);
        }

        if (! config('brandcoves.identity.allow_title_fallback')) {
            return null;
        }

        return self::fromBrandAndTitle($brand, $title);
    }

    /**
     * Brand + normalised title.
     *
     * Needed because a large share of feed rows carry no EAN at all — without
     * this path those products could never be compared across merchants, which
     * would gut offer comparison for most of the catalogue.
     *
     * The guards matter more than the matching: a bad merge here is silent and
     * user-visible, so anything ambiguous is deliberately left ungrouped.
     */
    public static function fromBrandAndTitle(?string $brand, string $title): ?Identity
    {
        $brandKey = self::normaliseBrand($brand);

        // No brand means no reliable discriminator. "Wireless Headphones" from
        // two shops is very unlikely to be the same object.
        if ($brandKey === null) {
            return null;
        }

        $titleKey = self::normaliseTitle($title);

        // Short titles collide trivially: "Cable", "Case", "Hoes".
        if (mb_strlen($titleKey) < (int) config('brandcoves.identity.min_title_length')) {
            return null;
        }

        // A title that is only the brand name carries no product information.
        if ($titleKey === $brandKey) {
            return null;
        }

        return new Identity($brandKey.'|'.$titleKey, IdentityKind::Title);
    }

    public static function normaliseBrand(?string $brand): ?string
    {
        if ($brand === null) {
            return null;
        }

        // Same punctuation folding as the title, for two reasons: the two keys
        // are compared against each other below, and without it "Bang &
        // Olufsen" and "Bang and Olufsen" from two feeds would produce
        // different group keys — a wrong split rather than a wrong merge, but
        // still wrong.
        $key = self::fold($brand);
        $key = (string) preg_replace('/[^a-z0-9]+/u', ' ', $key);
        $key = trim((string) preg_replace('/\s+/u', ' ', $key));

        // Feeds use these to mean "unbranded", which is not a brand.
        $meaningless = ['', 'n a', 'na', 'none', 'geen', 'merkloos', 'unbranded', 'generic', 'no brand', 'other', 'overig'];

        return in_array($key, $meaningless, true) ? null : $key;
    }

    /**
     * Aggressive and deliberately lossy. Precision over recall: it is better to
     * miss a merge than to make a wrong one.
     */
    public static function normaliseTitle(string $title): string
    {
        $value = self::fold($title);

        // Parenthesised and bracketed asides are almost always merchant noise.
        $value = (string) preg_replace('/\([^)]*\)|\[[^\]]*\]/u', ' ', $value);

        foreach (self::NOISE as $pattern) {
            $value = (string) preg_replace($pattern, ' ', $value);
        }

        // Collapse whatever is left.
        $value = (string) preg_replace('/[^a-z0-9]+/u', ' ', $value);
        $value = trim((string) preg_replace('/\s+/u', ' ', $value));

        // Sort nothing and drop nothing else: word order carries real meaning
        // ("case for iPhone" vs "iPhone for case" is not a distinction feeds
        // make, but "XM5" vs "XM4" is, and both survive this).
        return $value;
    }

    /** Lowercase, strip accents, normalise ligatures. */
    private static function fold(string $value): string
    {
        $value = mb_strtolower(trim($value));

        $transliterated = @iconv('UTF-8', 'ASCII//TRANSLIT', $value);
        if ($transliterated !== false) {
            // iconv emits things like "e for è on some platforms.
            $value = (string) preg_replace('/[\'"^~`]/', '', $transliterated);
        }

        $value = str_replace(['ß', 'æ', 'ø', 'đ'], ['ss', 'ae', 'o', 'd'], $value);

        return trim((string) preg_replace('/\s+/', ' ', $value));
    }
}
