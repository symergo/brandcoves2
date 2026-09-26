<?php

declare(strict_types=1);

namespace App\Services\Ideas;

use Illuminate\Support\Str;

/**
 * What a hand-typed item title folds to, so that different people's ways of
 * writing one idea count as one.
 *
 * "Kookworkshop", "kook-workshop", "Een kook workshop!" and "kookworkshops"
 * are all `kookworkshop`. Pure, and the only place this rule lives: the
 * nightly count uses it to group titles, and the review screen to recognise an
 * approved idea's own wording coming back.
 *
 * ## The steps, and why each is there
 *
 * 1. **Lowercase, accents off** ("Café" is "cafe"). People type the same word
 *    with and without them.
 * 2. **Every word with a digit goes**: amounts, years, dates, sizes, counts
 *    ("2x", "€50", "2026", "12/10", "42"). They are what makes one person's
 *    item theirs ("tickets 12 oktober") and never what makes it an idea.
 * 3. **Sizes and money words go** ("xl", "maat", "euro"), for the same reason.
 * 4. **Small words go**, per language plus English, which people mix in
 *    everywhere: articles, "for", "my", "with". "Een kookworkshop voor mijn
 *    zus" and "kookworkshop zus" meet.
 * 5. **A plural "s" goes** from a word longer than three letters, so
 *    "workshops" and "workshop" meet. Crude, and applied to every title
 *    alike, so both sides fold the same way even where it cuts a real "s".
 * 6. **The words are joined without spaces.** That is the Dutch compound
 *    rule: "kook workshop", "kook-workshop" and "kookworkshop" are the same
 *    three spellings of one word, and joining is what makes them one key.
 *    Word order still counts ("workshop koken" stays its own key); guessing
 *    that the two are the same would take a dictionary.
 *
 * ## What is refused (null)
 *
 * - nothing left after the steps;
 * - a key under four letters: too little to be an idea;
 * - **more than five words left**. A long title is a description of one
 *   particular thing ("the red scarf like the one mum had in Rome"), and the
 *   longer it is, the likelier it is to be personal. Ideas are short.
 */
final class IdeaKey
{
    /** More words than this is a description, not an idea. See above. */
    public const MAX_WORDS = 5;

    /** Shorter keys are too little to be an idea ("tv", "bon"). */
    public const MIN_LENGTH = 4;

    /** The column is 120 wide. */
    public const MAX_LENGTH = 120;

    /**
     * Words that never carry the idea, per language. English is always added,
     * because people write it on lists in every market.
     */
    private const STOP_WORDS = [
        'en' => [
            'a', 'an', 'the', 'for', 'to', 'of', 'and', 'or', 'with', 'my', 'our', 'your', 'his', 'her',
            'their', 'some', 'any', 'in', 'on', 'at', 'from', 'by', 'me', 'us', 'him', 'them', 'this',
            'that', 'new', 'nice', 'one', 'set', 'please', 'maybe',
        ],
        'nl' => [
            'een', 'de', 'het', 'voor', 'van', 'en', 'of', 'met', 'mijn', 'onze', 'ons', 'jouw', 'je',
            'zijn', 'haar', 'hun', 'wat', 'iets', 'in', 'op', 'aan', 'bij', 'naar', 'uit', 'om', 'te',
            'die', 'dat', 'dit', 'deze', 'nieuwe', 'nieuw', 'leuke', 'leuk', 'mooie', 'mooi', 'graag',
            'misschien', 'bv', 'zoals',
        ],
        'fr' => [
            'un', 'une', 'le', 'la', 'les', 'des', 'du', 'de', 'd', 'l', 'pour', 'et', 'ou', 'avec',
            'mon', 'ma', 'mes', 'ton', 'ta', 'tes', 'son', 'sa', 'ses', 'notre', 'nos', 'votre', 'vos',
            'leur', 'leurs', 'au', 'aux', 'en', 'dans', 'sur', 'chez', 'ce', 'cette', 'ces', 'nouveau',
            'nouvelle', 'joli', 'jolie', 'svp',
        ],
        'es' => [
            'un', 'una', 'unos', 'unas', 'el', 'la', 'los', 'las', 'de', 'del', 'para', 'por', 'y', 'o',
            'con', 'mi', 'mis', 'tu', 'tus', 'su', 'sus', 'nuestro', 'nuestra', 'al', 'en', 'a', 'este',
            'esta', 'ese', 'esa', 'nuevo', 'nueva', 'bonito', 'bonita',
        ],
    ];

    /** Sizes and money, in every language: never part of an idea. */
    private const NOISE = [
        'xxs', 'xs', 'xl', 'xxl', 'xxxl', 'maat', 'size', 'taille', 'talla',
        'euro', 'euros', 'eur', 'eu', 'cent', 'ct', 'prijs', 'price', 'prix', 'precio',
        'stuks', 'stuk', 'pcs', 'x',
    ];

    /**
     * The key a title counts under, or null when it cannot be one.
     *
     * @param  string  $language  the market's language: `nl`, `fr`, `en`, `es`
     */
    public static function of(string $title, string $language): ?string
    {
        $words = self::words($title, $language);

        if ($words === [] || count($words) > self::MAX_WORDS) {
            return null;
        }

        $key = implode('', $words);

        if (strlen($key) < self::MIN_LENGTH || strlen($key) > self::MAX_LENGTH) {
            return null;
        }

        return $key;
    }

    /**
     * The words left after every step but the join. Exposed for the tests
     * and for the review screen, which shows why two spellings met.
     *
     * @return list<string>
     */
    public static function words(string $title, string $language): array
    {
        $text = Str::ascii(mb_strtolower(str_replace(['’', '‘'], "'", $title)));

        // "l'atelier", "d'oenologie": a French elision splits, so "l" and "d"
        // fall away as small words. Any other apostrophe joins: "anna's" is
        // one word, and its "s" goes with the plural step.
        $text = (string) preg_replace("/\\b([a-z])'/", '$1 ', $text);
        $text = str_replace(["'", '`'], '', $text);
        $text = (string) preg_replace('/[^a-z0-9]+/', ' ', $text);

        $stop = array_flip([...self::STOP_WORDS['en'], ...(self::STOP_WORDS[$language] ?? []), ...self::NOISE]);

        $words = [];

        foreach (preg_split('/\s+/', trim($text), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $word) {
            if (preg_match('/\d/', $word) || strlen($word) < 2 || isset($stop[$word])) {
                continue;
            }

            if (strlen($word) > 3 && str_ends_with($word, 's') && ! str_ends_with($word, 'ss')) {
                $word = substr($word, 0, -1);
            }

            $words[] = $word;
        }

        return $words;
    }
}
