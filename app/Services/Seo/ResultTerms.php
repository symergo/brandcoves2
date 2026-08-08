<?php

declare(strict_types=1);

namespace App\Services\Seo;

use App\Enums\Market;
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
 * ## What gets excluded, and why each exclusion matters
 *
 * - **The query terms themselves.** Echoing "bluetooth" back at someone who
 *   searched for "bluetooth" adds nothing and reads as filler.
 * - **Stopwords, per language.** Without them the list is "de, met, voor".
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
     * The most characteristic words in a set of groups.
     *
     * @param  list<ProductGroup>  $groups
     * @return list<string>
     */
    public function extract(array $groups, Market $market, string $query = '', int $limit = 8): array
    {
        $stop = $this->stopwords($market, $query);
        $counts = [];
        $display = [];

        foreach ($groups as $group) {
            /*
             * Each title contributes a word at most once. Without the unique(),
             * a set of twelve near-identical listings for the same product makes
             * that product's model name the page's defining vocabulary.
             */
            foreach (array_unique($this->words((string) $group->title)) as $word) {
                $lowered = mb_strtolower($word);

                if (mb_strlen($lowered) < 3 || isset($stop[$lowered]) || is_numeric($lowered)) {
                    continue;
                }

                $counts[$lowered] = ($counts[$lowered] ?? 0) + 1;
                // Keep the first-seen casing: "Bluetooth" reads better than
                // "bluetooth", and title-casing everything would produce
                // "Usb-C".
                $display[$lowered] ??= $word;
            }
        }

        // A word in one title of many does not characterise the page.
        $counts = array_filter($counts, fn (int $n) => $n >= 2);

        arsort($counts);

        return array_values(array_map(
            fn (string $lowered) => $display[$lowered],
            array_slice(array_keys($counts), 0, $limit),
        ));
    }

    /** @return list<string> */
    private function words(string $title): array
    {
        // Keeps hyphens and apostrophes inside words, so "over-ear" and
        // "L'Oréal" survive as single terms rather than becoming four.
        preg_match_all("/[\p{L}\p{N}][\p{L}\p{N}'’\-]*/u", $title, $matches);

        return $matches[0] ?? [];
    }

    /** @return array<string, true> */
    private function stopwords(Market $market, string $query): array
    {
        $words = self::STOPWORDS[$market->language()] ?? self::STOPWORDS['en'];

        // The query's own words are stopwords for this page: repeating them back
        // is filler, and the query already appears in the heading, the title and
        // the lead sentence.
        foreach ($this->words($query) as $word) {
            $words[] = mb_strtolower($word);
        }

        return array_fill_keys($words, true);
    }
}
