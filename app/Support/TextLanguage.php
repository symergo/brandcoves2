<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Which language a piece of shop copy is written in, guessed from its common words.
 *
 * For one decision: is this description in the page's language (2026-11-26, a French
 * bol description on the be-nl page of a Kärcher steam cleaner; bol answers `nl-BE`
 * with whatever the seller wrote). Not a general detector, and it does not need to be:
 * the candidates are the site's four languages, and a shop description is long enough
 * that its little words give it away.
 *
 * Only words that belong to one language count. "de" and "en" are in Dutch, French and
 * Spanish alike and are left out; "la" counts for both French and Spanish, which the
 * other words then settle.
 */
final class TextLanguage
{
    /** Below this many hits there is no guess: a spec list or a few words says too little. */
    private const MIN_HITS = 3;

    /** The winner needs twice the runner-up's hits, or the text is mixed and there is no guess. */
    private const MARGIN = 2;

    private const WORDS = [
        'nl' => ['het', 'een', 'van', 'met', 'voor', 'je', 'jouw', 'niet', 'op', 'te', 'dat', 'zijn', 'deze', 'ook', 'wordt', 'aan', 'bij', 'uw', 'naar', 'heeft', 'kunt', 'zonder', 'alle', 'maar', 'tot'],
        'fr' => ['le', 'la', 'les', 'des', 'une', 'et', 'du', 'pour', 'avec', 'est', 'vous', 'sur', 'dans', 'au', 'aux', 'qui', 'par', 'cette', 'votre', 'vos', 'sans', 'grâce', 'très', 'plus'],
        'en' => ['the', 'and', 'with', 'for', 'you', 'your', 'this', 'of', 'to', 'on', 'it', 'that', 'are', 'from', 'without', 'can', 'will', 'all'],
        'es' => ['el', 'los', 'las', 'la', 'una', 'con', 'para', 'por', 'del', 'que', 'su', 'sus', 'y', 'sin', 'muy', 'más', 'este', 'esta'],
    ];

    /** 'nl', 'fr', 'en' or 'es'; null when the text does not say clearly enough. */
    public static function guess(string $text): ?string
    {
        preg_match_all('/\p{L}+/u', mb_strtolower($text), $m);
        $counts = array_count_values($m[0]);

        $hits = [];
        foreach (self::WORDS as $language => $words) {
            $hits[$language] = array_sum(array_map(fn (string $w): int => $counts[$w] ?? 0, $words));
        }

        arsort($hits);
        [$first, $second] = array_values($hits);

        return $first >= self::MIN_HITS && $first >= self::MARGIN * $second
            ? (string) array_key_first($hits)
            : null;
    }
}
