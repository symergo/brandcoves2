<?php

declare(strict_types=1);

namespace App\Services\Gift;

use Illuminate\Support\Str;

/**
 * What a product is probably for, read from its own title and category.
 *
 * For This or that (2026-09-26). Of production's roughly 150,000 giftable
 * products per market some 700 carry an interest tag, so choosing between
 * the rest taught the tool nothing but a price. A word in the product's own
 * title or category is weaker evidence than a tag and is weighted so (see
 * TasteCard), but every product has one.
 *
 * Pure and deterministic: the word lists live in
 * `resources/content/interest-words.php`, whose header says how a word
 * matches and why. No AI; this runs inside a visitor's request.
 */
final class InterestGuesser
{
    /**
     * One regular expression per interest, every word an alternative in it:
     * forty matches per product rather than eight hundred, which matters on a
     * pool drawn inside a request.
     *
     * @var array<string, string> interest => pattern
     */
    private array $interests;

    private ?string $notGifts;

    /**
     * @param  array{interests: array<string, list<string>>, not_gifts: list<string>}|null  $words
     *                                                                                              null reads the content file
     */
    public function __construct(?array $words = null)
    {
        $words ??= require resource_path('content/interest-words.php');

        $this->interests = array_filter(array_map(self::pattern(...), $words['interests']));
        $this->notGifts = self::pattern($words['not_gifts']);
    }

    /**
     * The interests these words point at, in the order the list declares them.
     *
     * @return list<string>
     */
    public function interests(string $title, ?string $category = null): array
    {
        $text = self::normalise($title.' '.$category);
        $found = [];

        foreach ($this->interests as $interest => $pattern) {
            if (preg_match($pattern, $text) === 1) {
                $found[] = $interest;
            }
        }

        return $found;
    }

    /** Whether this is something nobody unwraps: a part, a case, a cable, a supply. */
    public function isNotAGift(string $title, ?string $category = null): bool
    {
        return $this->notGifts !== null && preg_match($this->notGifts, self::normalise($title.' '.$category)) === 1;
    }

    /** Lowercase, no accents, punctuation as spaces, one space between words. */
    private static function normalise(string $text): string
    {
        $text = mb_strtolower(Str::ascii($text));
        $text = (string) preg_replace('/[^a-z0-9]+/', ' ', $text);

        return ' '.trim($text).' ';
    }

    /**
     * A list of words as one regular expression. Five letters or more match
     * at the start of a word ("koffie" finds "koffiemolen"); shorter ones, and
     * any written with a leading "=", only as the whole word ("pan", "=filter").
     *
     * @param  list<string>  $words
     */
    private static function pattern(array $words): ?string
    {
        $alternatives = [];

        foreach ($words as $word) {
            $whole = str_starts_with($word, '=');
            $word = trim(self::normalise(ltrim($word, '=')));

            if ($word !== '') {
                $alternatives[] = preg_quote($word, '/').($whole || strlen($word) < 5 ? '(?![a-z0-9])' : '');
            }
        }

        return $alternatives === [] ? null : '/(?<![a-z0-9])(?:'.implode('|', $alternatives).')/';
    }
}
