<?php

declare(strict_types=1);

namespace App\Services\Seo;

use App\Models\ProductGroup;

/**
 * The words that actually appear in a page of results.
 *
 * ## What this is for
 *
 * A results page needs prose, and the only prose worth writing about a result
 * set is a description of the result set. "Terms that come up in these results:
 * noise cancelling, over-ear, Bluetooth" is a sentence built from the page's own
 * content — it tells a reader what kind of thing they are looking at, and it puts
 * the long-tail vocabulary of the category on a page that would otherwise contain
 * only product titles.
 *
 * ## Why it is extraction, not generation
 *
 * The obvious alternative is to ask a model for "related keywords". That
 * produces plausible words the page does not contain, which is keyword stuffing
 * with extra steps and a lie about the page's contents. Counting the words that
 * are genuinely there cannot do that, and it costs one pass over 24 titles.
 *
 * ## Phrases, not loose words
 *
 * "noise" and "cancelling" as two separate chips are worse than useless: each
 * one on its own is not a thing anybody searches for, and clicking one narrows
 * the page by half a concept. Adjacent word pairs are counted alongside single
 * words, and a phrase **absorbs** the words inside it when they nearly always
 * occur together — so the list says "noise cancelling" and stops offering the
 * halves.
 *
 * ## What gets excluded, and why each exclusion matters
 *
 * - **The query terms themselves.** Echoing "bluetooth" back at someone who
 *   searched for "bluetooth" adds nothing and reads as filler.
 * - **Stopwords, in every language at once** — see {@see stopwords()}.
 * - **Anything under three characters, and pure numbers.** Model numbers and
 *   capacities ("512", "gb") are not vocabulary; they are noise that makes the
 *   sentence look machine-made.
 * - **Words appearing once.** A term that occurs in one of 24 titles does not
 *   characterise the page, and including it is how you end up describing a page
 *   of headphones with the word "keukenmachine".
 */
class ResultTerms
{
    /**
     * Stopwords per language.
     *
     * Short lists on purpose: these run against product titles, not prose, so
     * the words that actually pollute the output are articles, prepositions and
     * the handful of retail filler words every feed repeats.
     *
     * @var array<string, list<string>>
     */
    private const STOPWORDS = [
        'nl' => [
            'de', 'het', 'een', 'en', 'met', 'voor', 'van', 'in', 'op', 'aan', 'bij', 'tot',
            'zwart', 'wit', 'stuks', 'stuk', 'set', 'incl', 'inclusief', 'nieuw', 'origineel',
            'cm', 'mm', 'ml', 'gr', 'kg', 'stk',
        ],
        'en' => [
            'the', 'and', 'with', 'for', 'from', 'this', 'that', 'pack', 'set', 'new',
            'black', 'white', 'inch', 'pcs', 'piece', 'pieces', 'includes', 'including',
            'cm', 'mm', 'ml', 'kg',
        ],
        'fr' => [
            'le', 'la', 'les', 'un', 'une', 'des', 'et', 'avec', 'pour', 'de', 'du', 'en',
            'noir', 'blanc', 'lot', 'set', 'pieces', 'pièces', 'nouveau', 'inclus',
            'cm', 'mm', 'ml', 'kg',
        ],
        'es' => [
            'el', 'la', 'los', 'las', 'un', 'una', 'de', 'del', 'con', 'para', 'y', 'en',
            'negro', 'blanco', 'set', 'pack', 'piezas', 'nuevo', 'incluye',
            'cm', 'mm', 'ml', 'kg',
        ],
    ];

    /**
     * How much of a word's occurrences a phrase has to account for before the
     * word stops being offered on its own.
     *
     * At 0.6, "noise" appearing 10 times with "noise cancelling" 7 of them is
     * absorbed, while a genuinely independent word that happens to pair up
     * sometimes keeps its own chip. Tuned to swallow the obvious compounds and
     * leave real vocabulary alone.
     */
    private const ABSORB = 0.6;

    /**
     * The most characteristic terms in a set of groups.
     *
     * Takes no market. It used to, to pick a stopword list — see
     * {@see stopwords()} for why choosing one was the bug rather than the
     * feature.
     *
     * @param  list<ProductGroup>  $groups
     * @return list<string>
     */
    public function extract(array $groups, string $query = '', int $limit = 8): array
    {
        $stop = $this->stopwords($query, $groups);

        /** @var array<string, int> $words */
        $words = [];
        /** @var array<string, int> $phrases */
        $phrases = [];
        /** @var array<string, string> $display */
        $display = [];
        /** @var array<string, array{0: string, 1: string}> $parts */
        $parts = [];

        foreach ($groups as $group) {
            $tokens = $this->tokens((string) $group->title, $stop);

            /*
             * Each title contributes a term at most once. Without the unique(),
             * a set of twelve near-identical listings for the same product makes
             * that product's model name the page's defining vocabulary.
             */
            $seenWords = [];
            $seenPhrases = [];

            foreach ($tokens as $i => $token) {
                if ($token === null) {
                    continue;
                }

                $key = mb_strtolower($token);
                $display[$key] ??= $token;

                if (! isset($seenWords[$key])) {
                    $words[$key] = ($words[$key] ?? 0) + 1;
                    $seenWords[$key] = true;
                }

                /*
                 * A pair, only when the two words are genuinely adjacent in the
                 * title. A `null` token is a stopword, a number or punctuation,
                 * and it breaks the run — otherwise "headphones with case"
                 * would yield the phrase "headphones case", which appears
                 * nowhere and reads as a machine's idea of language.
                 */
                $next = $tokens[$i + 1] ?? null;

                if ($next === null) {
                    continue;
                }

                $pair = $key.' '.mb_strtolower($next);
                $display[$pair] ??= $token.' '.$next;
                $parts[$pair] = [$key, mb_strtolower($next)];

                if (! isset($seenPhrases[$pair])) {
                    $phrases[$pair] = ($phrases[$pair] ?? 0) + 1;
                    $seenPhrases[$pair] = true;
                }
            }
        }

        // A term in one title of many does not characterise the page.
        $words = array_filter($words, fn (int $n) => $n >= 2);
        $phrases = array_filter($phrases, fn (int $n) => $n >= 2);

        arsort($phrases);

        /*
         * Phrases must not overlap.
         *
         * "Sony draadloze koptelefoon model 3" yields both "draadloze
         * koptelefoon" and "koptelefoon model", and offering both is one idea
         * chopped into a chain — the reader gets two chips that share a word and
         * neither is a thing anybody would type. Strongest first, and a phrase
         * is skipped once either of its words has already been spoken for.
         */
        $kept = [];
        $used = [];

        foreach ($phrases as $pair => $count) {
            [$left, $right] = $parts[$pair];

            if (isset($used[$left]) || isset($used[$right])) {
                continue;
            }

            $kept[$pair] = $count;
            $used[$left] = true;
            $used[$right] = true;
        }

        /*
         * A phrase absorbs its own words.
         *
         * "noise" and "cancelling" beside "noise cancelling" is three chips for
         * one idea, and two of them narrow the page by half a concept. A word is
         * dropped once a kept phrase accounts for most of its occurrences.
         */
        $absorbed = [];

        foreach ($kept as $pair => $count) {
            foreach ($parts[$pair] as $part) {
                if (($words[$part] ?? 0) > 0 && $count / $words[$part] >= self::ABSORB) {
                    $absorbed[$part] = true;
                }
            }
        }

        $candidates = $kept;

        foreach ($words as $word => $count) {
            if (! isset($absorbed[$word])) {
                // Phrases win ties: given the same count, "noise cancelling"
                // says more than "cancelling".
                $candidates[$word] ??= $count;
            }
        }

        arsort($candidates);

        return array_values(array_map(
            fn (string $key) => $display[$key],
            array_slice(array_keys($candidates), 0, $limit),
        ));
    }

    /**
     * A title as a run of usable words, with `null` wherever the run breaks.
     *
     * The nulls are the point: they are what stops two words either side of a
     * stopword being read as a phrase.
     *
     * @param  array<string, true>  $stop
     * @return list<string|null>
     */
    private function tokens(string $title, array $stop): array
    {
        return array_map(function (string $word) use ($stop): ?string {
            $lowered = mb_strtolower($word);

            if (mb_strlen($lowered) < 3 || isset($stop[$lowered]) || is_numeric($lowered)) {
                return null;
            }

            // Keep the first-seen casing: "Bluetooth" reads better than
            // "bluetooth", and title-casing everything would produce "Usb-C".
            return $word;
        }, $this->words($title));
    }

    /** @return list<string> */
    private function words(string $title): array
    {
        // Keeps hyphens and apostrophes inside words, so "over-ear" and
        // "L'Oréal" survive as single terms rather than becoming four.
        preg_match_all("/[\p{L}\p{N}][\p{L}\p{N}'’\-]*/u", $title, $matches);

        return $matches[0] ?? [];
    }

    /**
     * Every language's stopwords at once, plus the query's own words.
     *
     * **Not the market's language.** These run against titles written by third
     * parties, and a Belgian feed is full of "Wireless Bluetooth Headphones
     * with Noise Cancelling" — so filtering by `nl` alone left "and", "with"
     * and "for" as some of the most frequent words on the page, which is
     * exactly the list this class exists to avoid. The lists are disjoint
     * enough that unioning them costs nothing real: the only genuine collision
     * is Spanish "la"/"los" against French articles, and both are stopwords in
     * both languages anyway.
     *
     * The query's own words are stopwords for this page: repeating them back is
     * filler, and the query already appears in the heading, the title and the
     * lead sentence.
     *
     * **So are the brands in the result set.** A product title is
     * overwhelmingly "Brand Attribute Noun", so without this the strongest
     * phrase on a page is routinely the brand plus whatever word follows it —
     * "Aurex draadloze", which is not a thing anybody would type. Brands are
     * already a filter of their own with their own pages, so a brand name has
     * never been the vocabulary this row is for.
     *
     * @param  list<ProductGroup>  $groups
     * @return array<string, true>
     */
    private function stopwords(string $query, array $groups): array
    {
        $words = array_merge(...array_values(self::STOPWORDS));

        foreach ($this->words($query) as $word) {
            $words[] = mb_strtolower($word);
        }

        foreach ($groups as $group) {
            // A brand can be several words ("Audio Technica"), and each of them
            // has to break a phrase rather than only the whole string.
            foreach ($this->words((string) $group->brand) as $word) {
                $words[] = mb_strtolower($word);
            }
        }

        return array_fill_keys($words, true);
    }
}
