<?php

declare(strict_types=1);

namespace App\Services\Cove;

use Illuminate\Support\Str;

/**
 * Does a product belong on a themed Daily Cove that nobody curated?
 *
 * The question the full-text search could not answer. A Daily that nobody
 * curated is filled from its theme's search words, and until 2026-09-27 that
 * meant `products.search_vector` — title, brand, category *and description*.
 * "Werelddag van het toerisme" (27 Sep 2026, words koffer, reisadapter,
 * nekkussen) published this on be-nl, unattended:
 *
 *   - a vibrator, whose description says it fits in your suitcase;
 *   - a Nanoleaf light kit and a tablet car holder, the same way;
 *   - a STANLEY gereedschapkoffer, a tool case;
 *   - IRWIN gatenzagen "in koffer", hole saws sold in a case;
 *
 * and one suitcase. nl-nl got two DeWalt drill sets "in koffer", a laser level
 * and a Littlest Pet Shop set beside its travel adapter. Ranked by surprise
 * score, because the stranger a product is, the higher it scores, so the
 * wrong matches were exactly the ones that won.
 *
 * Three rules, each answering one of those failures:
 *
 * **1. Only the title and the category count, never the description.** A
 * description mentions everything the product is near: where it fits, what it
 * comes in, what it pairs with. The title and the feed's category say what the
 * product *is*. That alone removes the vibrator, the light kit and the car
 * holder.
 *
 * **2. A word that says what the product came in does not count.** "in koffer",
 * "met koffer", "inclusief opbergkoffer": the case is the packaging, and the
 * product is a hole saw, a drill, a microscope. Checked on the two words before
 * the match, in the title only (a category never describes packaging).
 *
 * **3. A compound counts when the theme word is its last part, unless the
 * first part makes it a tool, storage, a toy or a craft item.** Dutch puts the
 * kind of thing last: a reiskoffer and a handbagagekoffer are suitcases, and a
 * kofferdiepvriezer (chest freezer) or a kofferlabel is not, so the word has to
 * be the *end* of the compound. But lexically a gereedschapskoffer is a koffer
 * as well, and so are the dokterskoffer and the knutselkoffer that score in the
 * eighties on be-nl's toy shelf. No spelling rule tells those apart from a
 * reiskoffer, so {@see self::OTHER_USES} names the first parts that turn a
 * container or an everyday noun into something else. It is a short list on
 * purpose: the theme words are overwhelmingly nouns (koffer, mand, tas, set,
 * pot, doos), and it is the same few trades that borrow them.
 *
 * Short theme words (under four letters) never match as the end of a compound
 * ("pet" ends "trompet", "nas" ends "ananas"), and count in the category only:
 * in a title "pet" is as often the English word or a PET bottle.
 *
 * **Then the category ranks ahead of the title** ({@see self::strength()}):
 * the category is the feed's statement of what the product is, the title the
 * seller's advert.
 *
 * **Being wrong in the strict direction is cheap.** A real suitcase the rule
 * misses is one fewer candidate among dozens; a tool case it lets through is a
 * page that looks assembled by nobody. So when in doubt this says no.
 *
 * What it does not do: stem English, French or Spanish, or read multi-word
 * English modifiers ("tool case"). The calendar's search words are Dutch
 * (config/observances.php says why), and those are the words this reads.
 * Accents are folded (`Str::ascii`), so "café" and "cafe" are one word.
 *
 * Pure: no database, no config. The SQL side only narrows the candidates to
 * titles or categories that contain the words at all ({@see self::terms()});
 * this class makes the decision. See docs/features/daily-cove.md.
 */
final readonly class ThemeRelevance
{
    /**
     * First parts of a compound that make it a different thing from the theme.
     *
     * Grouped by the trade that borrows the noun, and matched as the start of
     * the first part, so the linking "s" of "gereedschapskoffer" and the plural
     * of "dokterskoffer" need no spelling of their own.
     */
    public const OTHER_USES = [
        // Tools and workshop: gereedschapskoffer, boorkoffer, bitset, dopset.
        'gereedschap', 'boor', 'bit', 'dop', 'zaag', 'sleutel', 'werk', 'tool',
        // Storage and carrying: opbergkoffer, bewaardoos, sorteerdoos,
        // draagkoffer, meeneemkoffer. The case, not the thing in it.
        'opberg', 'bewaar', 'sorteer', 'draag', 'meeneem', 'transport',
        // Toys and craft: dokterskoffer, knutselkoffer, speelkoffer,
        // verzamelkoffer, poppenhuiskoffer.
        'speel', 'dokter', 'knutsel', 'verzamel', 'poppenhuis', 'kapper',
        // Beauty and sewing: visagiekoffer, cosmeticakoffer, naaikoffer.
        'visagie', 'cosmetica', 'makeup', 'naai',
    ];

    /**
     * Words that mark what follows as packaging or an accessory in the box.
     *
     * Dutch first, because the theme words are, then the German, English,
     * French and Spanish feeds' spellings. "en" is deliberately absent: it is
     * Dutch for "and" far more often than Spanish for "in".
     */
    private const PACKAGING = ['in', 'met', 'incl', 'inclusief', 'inkl', 'mit', 'with', 'including', 'avec', 'dans', 'con'];

    /** How far back a packaging word is looked for: "in koffer", "inclusief handige opbergkoffer". */
    private const PACKAGING_REACH = 2;

    /** Shorter theme words only match whole: "pet" ends "trompet", "nas" ends "ananas". */
    private const COMPOUND_MIN_TERM = 4;

    /** A first part shorter than this is probably not a word ("s" + "koffer"). "rol" + "koffer" is three. */
    private const COMPOUND_MIN_MODIFIER = 3;

    /** @see self::strength() */
    public const NONE = 0;

    public const TITLE = 1;

    public const CATEGORY = 2;

    /** @var list<list<string>> each query, folded to its words */
    private array $queries;

    /** @param  list<string>  $queries  the theme's search words, as the plan or the calendar holds them */
    public function __construct(array $queries)
    {
        $folded = [];

        foreach ($queries as $query) {
            $words = self::words((string) $query);

            if ($words !== []) {
                $folded[] = $words;
            }
        }

        $this->queries = $folded;
    }

    /** Is there any theme to be relevant to? With no words, nothing can match. */
    public function hasTheme(): bool
    {
        return $this->queries !== [];
    }

    /**
     * The words the SQL narrows on, one list per query.
     *
     * A title or category must contain every word of some query as a
     * substring before this class is asked. Each word is given in its shortest
     * form (the singular of "ruilkaarten"), which every form `matches()`
     * accepts contains, so narrowing on it never drops what this class would
     * keep; it only saves reading a whole market into PHP. The one gap is
     * accents: the database compares them as written, and folding them there
     * would cost the title's trigram index. Dutch product nouns rarely carry
     * one.
     *
     * @return list<list<string>>
     */
    public function terms(): array
    {
        return array_map(
            fn (array $query) => array_map(
                function (string $word): string {
                    $forms = self::forms($word);
                    usort($forms, fn (string $a, string $b) => strlen($a) <=> strlen($b));

                    return $forms[0];
                },
                $query,
            ),
            $this->queries,
        );
    }

    /** Is this product about the theme, by what its title or its category says it is? */
    public function matches(string $title, ?string $category): bool
    {
        return $this->strength($title, $category) > self::NONE;
    }

    /**
     * How sure the match is: the category says so, only the title does, or neither.
     *
     * The category ranks first because it is the feed's own statement of what
     * the product is, where the title is the seller's advert for it. Read on
     * be-nl's catalogue, a title-only "koffer" still lets through a fireproof
     * document case and a toy called "Koffer Blokjes en Stokjes", and ranked by
     * surprise alone those outscore every product filed under Reiskoffer. So
     * the builder takes the category-confirmed products first and ranks by
     * surprise within each tier.
     */
    public function strength(string $title, ?string $category): int
    {
        $titleWords = self::words($title);
        $categoryWords = self::words((string) $category);
        $best = self::NONE;

        foreach ($this->queries as $query) {
            if ($this->found($query, $categoryWords, checkPackaging: false)) {
                return self::CATEGORY;
            }

            /*
             * A word under four letters counts in the category only.
             *
             * In a title it is as often another language's word or an
             * abbreviation: on hat day ("muts", "pet", "hoed") be-nl's
             * highest-scoring "pet" titles were a PET monitoring camera, a
             * carpet cleaner for pet hair and a pack of PET bottles. A category
             * of Pet or Petten is not ambiguous.
             */
            if ($this->short($query)) {
                continue;
            }

            if ($this->found($query, $titleWords, checkPackaging: true)) {
                $best = self::TITLE;
            }
        }

        return $best;
    }

    /** @param list<string> $query */
    private function short(array $query): bool
    {
        return count($query) === 1 && strlen($query[0]) < self::COMPOUND_MIN_TERM;
    }

    /**
     * One query against one field's words.
     *
     * A one-word query must name a word of the field (rules 2 and 3). A
     * multi-word query ("pizza oven", "matras topper") matches when every one
     * of its words is there whole, or when it is written as one compound
     * ("pizzaoven"), which Dutch feeds do about half the time.
     *
     * @param  list<string>  $query
     * @param  list<string>  $words
     */
    private function found(array $query, array $words, bool $checkPackaging): bool
    {
        if ($words === []) {
            return false;
        }

        if (count($query) > 1) {
            $first = null;

            foreach ($query as $part) {
                $at = $this->position($part, $words);

                if ($at === null) {
                    $first = null;
                    break;
                }

                $first ??= $at;
            }

            if ($first !== null && (! $checkPackaging || ! $this->packaged($words, $first))) {
                return true;
            }
        }

        $term = implode('', $query);

        foreach ($words as $index => $word) {
            if ($this->names($word, $term) && (! $checkPackaging || ! $this->packaged($words, $index))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Where the term stands as a whole word, if it does.
     *
     * @param  list<string>  $words
     */
    private function position(string $term, array $words): ?int
    {
        foreach ($words as $index => $word) {
            if ($this->whole($word, $term)) {
                return $index;
            }
        }

        return null;
    }

    /** Does this word name the term: the term itself, or a compound whose last part it is? */
    private function names(string $word, string $term): bool
    {
        if ($this->whole($word, $term)) {
            return true;
        }

        if (strlen($term) < self::COMPOUND_MIN_TERM) {
            return false;
        }

        foreach (self::forms($term) as $form) {
            if (! str_ends_with($word, $form)) {
                continue;
            }

            $modifier = substr($word, 0, strlen($word) - strlen($form));

            if (strlen($modifier) < self::COMPOUND_MIN_MODIFIER) {
                continue;
            }

            foreach (self::OTHER_USES as $other) {
                if (str_starts_with($modifier, $other)) {
                    return false;
                }
            }

            return true;
        }

        return false;
    }

    private function whole(string $word, string $term): bool
    {
        return in_array($word, self::forms($term), true);
    }

    /**
     * The term and the endings a Dutch feed puts on it.
     *
     * Plurals (koffers, tassen, potten), the -e of an inflected adjective
     * (herbruikbare), and the singular of a query written as a plain -s or -en
     * plural (ruilkaarten finds "ruilkaart"). Irregular plurals (glas, glazen)
     * are out of reach, which only ever costs a candidate. Deliberately crude:
     * an ending too many can only widen a match on a word that already spells
     * the term out.
     *
     * @return list<string>
     */
    private static function forms(string $term): array
    {
        $last = substr($term, -1);

        $forms = [$term, $term.'s', $term.'en', $term.'es', $term.'e', $term.'n', $term.$last.'en'];

        if (str_ends_with($term, 'en') && strlen($term) > 4) {
            $forms[] = substr($term, 0, -2);
        } elseif (str_ends_with($term, 's') && strlen($term) > 3) {
            $forms[] = substr($term, 0, -1);
        }

        return array_values(array_unique($forms));
    }

    /**
     * Is the word at `$index` what the product came in, rather than what it is?
     *
     * @param  list<string>  $words
     */
    private function packaged(array $words, int $index): bool
    {
        for ($back = 1; $back <= self::PACKAGING_REACH; $back++) {
            if (in_array($words[$index - $back] ?? null, self::PACKAGING, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Lower-cased, accent-folded words.
     *
     * Split on anything that is not a letter or a digit, so "Kofferset -
     * 143L" is ["kofferset", "143l"] and "Make-up" is ["make", "up"]. The
     * hyphen is the one place that loses something ("make-up-koffer" no longer
     * reads as one compound), and that loss is on the strict side.
     *
     * @return list<string>
     */
    private static function words(string $text): array
    {
        $folded = Str::lower(Str::ascii($text));

        return array_values(array_filter(
            preg_split('/[^a-z0-9]+/', $folded) ?: [],
            fn (string $word) => $word !== '',
        ));
    }
}
