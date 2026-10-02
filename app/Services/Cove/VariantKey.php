<?php

declare(strict_types=1);

namespace App\Services\Cove;

use Illuminate\Support\Str;

/**
 * One key for the sizes and colours of one product.
 *
 * A shop lists every size of a slipper as its own product, with its own
 * barcode, so the catalogue holds "Alwero Sloffen Basic Mono Naturel 37/38",
 * "39/40" and "41/42" as three products. That is right for a price comparison
 * and wrong on a Cove: be-fr's Daily of 2 Oct 2026 showed those three in a row,
 * and be-nl's showed the same Sonic slippers three times, because nothing told
 * the selector that they were one thing. Two products with the same key are
 * the same product to a reader.
 *
 * The key is the title folded to plain words, without size and colour words,
 * without the numbers that are sizes (a pair like 37/38, "maat 39", "500 ml"),
 * and cut to its first five words: a variant usually differs further along, in
 * the part a shop pads with selling points. Other numbers stay, because they
 * name the product ("iPhone 15" is not "iPhone 16"). Five words because a long
 * brand or licence name takes three or four on its own ("Sonic the Hedgehog"),
 * and the fifth is usually what the thing is. Two unbranded titles that open
 * with the same five words collide, and that is fine: they read as the same
 * product too. Pure and cheap, so it runs over a candidate list in PHP.
 */
final class VariantKey
{
    /** Words that only say which size or colour, in the four site languages. */
    private const NOISE = [
        // sizes
        'xxs', 'xs', 's', 'm', 'l', 'xl', 'xxl', 'xxxl', 'eu', 'small', 'medium', 'large',
        // colours
        'zwart', 'wit', 'grijs', 'rood', 'roze', 'blauw', 'groen', 'geel', 'oranje', 'paars', 'bruin', 'beige', 'naturel',
        'black', 'white', 'grey', 'gray', 'red', 'pink', 'blue', 'green', 'yellow', 'orange', 'purple', 'brown', 'natural',
        'noir', 'blanc', 'gris', 'rouge', 'rose', 'bleu', 'vert', 'jaune', 'violet', 'marron', 'naturelle',
        'navy', 'burgundy', 'bordeaux', 'creme', 'cream', 'antraciet', 'zilver', 'goud', 'silver', 'gold', 'taupe', 'khaki',
    ];

    /** Numbers that are a size: a size pair, a size word and its value, a measure. */
    private const SIZES = [
        '/\b\d{1,3}\s*[\/-]\s*\d{1,3}\b(?!\s*-?\s*\d)/u',
        '/\b(maat|mt|taille|size|gr\x{f6}\x{df}e)\s*[\d.,]+\b/u',
        '/\b\d+([.,]\d+)?\s*(mm|cm|m|ml|cl|l|g|gr|kg|oz)\b/u',
    ];

    private const WORDS = 5;

    public static function of(string $title): string
    {
        $text = preg_replace(self::SIZES, ' ', mb_strtolower($title)) ?? $title;
        $words = explode(' ', Str::slug($text, ' '));
        $noise = array_flip(self::NOISE);

        $kept = array_values(array_filter($words, fn (string $word) => $word !== '' && ! isset($noise[$word])));

        return implode(' ', array_slice($kept, 0, self::WORDS));
    }
}
