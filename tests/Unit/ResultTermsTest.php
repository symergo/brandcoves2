<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\ProductGroup;
use App\Services\Seo\ResultTerms;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The chips above a set of results.
 *
 * Two defects this pins, both of which made the row read as machine output:
 * common words like "and" survived because the stopword list was chosen by
 * *market* language while the titles are whatever the feed wrote, and every
 * term was a single word, so "noise cancelling" arrived as two chips that each
 * narrow the page by half a concept.
 */
class ResultTermsTest extends TestCase
{
    /**
     * Titles only. `ResultTerms` reads nothing else off a group, so unsaved
     * models keep this a unit test rather than a database one.
     *
     * @param  list<string>  $titles
     * @return list<ProductGroup>
     */
    private function asGroups(array $titles, ?string $brand = null): array
    {
        return array_map(function (string $title) use ($brand): ProductGroup {
            $group = new ProductGroup;
            $group->title = $title;
            $group->brand = $brand;

            return $group;
        }, $titles);
    }

    /** @param list<string> $titles */
    private function extract(array $titles, string $query = '', int $limit = 8, ?string $brand = null): array
    {
        return (new ResultTerms)->extract($this->asGroups($titles, $brand), $query, $limit);
    }

    #[Test]
    public function common_words_do_not_appear_even_in_another_markets_language(): void
    {
        /*
         * The bug. A Belgian feed is full of English titles, and the stopword
         * list was picked from the *market* — so on `be-nl`, "and", "with" and
         * "for" were among the most frequent words on the page and went
         * straight into the chip row.
         */
        $terms = $this->extract([
            'Wireless Headphones with Charging Case and Microphone',
            'Wireless Earbuds with Charging Case and Microphone',
            'Wireless Speaker with Charging Case and Microphone',
        ]);

        foreach (['and', 'with', 'the', 'for'] as $common) {
            $this->assertNotContains($common, array_map('mb_strtolower', $terms));
        }
    }

    #[Test]
    public function adjacent_words_are_kept_together(): void
    {
        // "noise" and "cancelling" as two chips is three chips for one idea,
        // and clicking either narrows the page by half a concept.
        $terms = $this->extract([
            'Sony Noise Cancelling Headphones',
            'Bose Noise Cancelling Headphones',
            'Philips Noise Cancelling Earbuds',
        ]);

        $lowered = array_map('mb_strtolower', $terms);

        $this->assertContains('noise cancelling', $lowered);
        $this->assertNotContains('noise', $lowered);
        $this->assertNotContains('cancelling', $lowered);
    }

    #[Test]
    public function a_word_that_stands_on_its_own_keeps_its_chip(): void
    {
        /*
         * The other side of the absorption rule. "Bluetooth" pairs with
         * "speaker" twice out of five, which is well under the threshold — it
         * is a real word about this page and must not vanish into one phrase
         * that only sometimes contains it.
         */
        $terms = $this->extract([
            'Bluetooth Speaker Waterproof',
            'Bluetooth Speaker Portable',
            'Bluetooth Headphones Wireless',
            'Bluetooth Keyboard Compact',
            'Bluetooth Mouse Silent',
        ]);

        $this->assertContains('bluetooth', array_map('mb_strtolower', $terms));
    }

    #[Test]
    public function a_stopword_breaks_a_phrase_rather_than_joining_it(): void
    {
        /*
         * "Headphones with Case" must not yield "headphones case". A phrase the
         * page does not contain is exactly the invented vocabulary this class
         * exists to avoid — the same objection as asking a model for keywords.
         */
        $terms = $this->extract([
            'Studio Headphones with Case',
            'Studio Headphones with Case',
            'Studio Headphones with Case',
        ]);

        $this->assertNotContains('headphones case', array_map('mb_strtolower', $terms));
        $this->assertContains('studio headphones', array_map('mb_strtolower', $terms));
    }

    #[Test]
    public function phrases_do_not_chain_through_a_shared_word(): void
    {
        /*
         * "draadloze koptelefoon" and "koptelefoon model" are one idea chopped
         * into a chain: two chips sharing a word, neither of which is a thing
         * anybody would type. The strongest phrase wins and the overlap is
         * dropped.
         */
        $terms = $this->extract(
            array_map(fn (int $i) => "Aurex draadloze koptelefoon model {$i}", range(0, 3)),
            brand: 'Aurex',
        );

        $lowered = array_map('mb_strtolower', $terms);

        $this->assertContains('draadloze koptelefoon', $lowered);
        $this->assertNotContains('koptelefoon model', $lowered);
    }

    #[Test]
    public function the_query_is_not_echoed_back(): void
    {
        // Repeating the search back at the searcher is filler; it already
        // appears in the heading, the title and the lead sentence.
        $terms = $this->extract([
            'Bluetooth Koptelefoon Draadloos',
            'Bluetooth Koptelefoon Draadloos',
        ], query: 'koptelefoon');

        foreach ($terms as $term) {
            $this->assertStringNotContainsStringIgnoringCase('koptelefoon', $term);
        }
    }

    #[Test]
    public function a_term_appearing_once_does_not_characterise_the_page(): void
    {
        // Otherwise a page of headphones gets described with the one word from
        // the one stray listing.
        $terms = $this->extract([
            'Draadloze Koptelefoon Premium',
            'Draadloze Koptelefoon Premium',
            'Keukenmachine Deluxe Roestvrij',
        ]);

        $this->assertNotContains('keukenmachine', array_map('mb_strtolower', $terms));
    }

    #[Test]
    public function numbers_and_very_short_tokens_are_not_vocabulary(): void
    {
        $terms = $this->extract([
            'SSD 512 GB NVMe Schijf',
            'SSD 512 GB NVMe Schijf',
        ]);

        $lowered = array_map('mb_strtolower', $terms);

        $this->assertNotContains('512', $lowered);
        $this->assertNotContains('gb', $lowered);
    }

    #[Test]
    public function one_title_contributes_a_term_once(): void
    {
        /*
         * Twelve near-identical listings for the same product would otherwise
         * make that product's model name the page's defining vocabulary. One
         * title repeating a word does not make it more characteristic.
         */
        $terms = $this->extract(['Premium Premium Premium Koptelefoon']);

        $this->assertSame([], $terms);
    }

    #[Test]
    public function it_respects_the_limit(): void
    {
        $titles = array_fill(0, 3, 'Alpha Bravo Charlie Delta Echo Foxtrot Golf Hotel India Juliet');

        $this->assertCount(4, $this->extract($titles, limit: 4));
    }

    #[Test]
    public function a_brand_name_is_never_the_vocabulary(): void
    {
        /*
         * A product title is overwhelmingly "Brand Attribute Noun", so without
         * this the strongest phrase on a page is routinely the brand plus
         * whatever follows it — "Aurex draadloze", which nobody would type.
         * Brands already have their own filter and their own pages.
         */
        $terms = $this->extract(
            ['Aurex Draadloze Koptelefoon', 'Aurex Draadloze Koptelefoon'],
            brand: 'Aurex',
        );

        foreach ($terms as $term) {
            $this->assertStringNotContainsStringIgnoringCase('aurex', $term);
        }
    }

    #[Test]
    public function a_multi_word_brand_breaks_a_phrase_in_both_halves(): void
    {
        // "Audio Technica" is two words, and each has to break a run — or
        // "Technica draadloze" survives as the page's defining phrase.
        $terms = $this->extract(
            ['Audio Technica Draadloze Koptelefoon', 'Audio Technica Draadloze Koptelefoon'],
            brand: 'Audio Technica',
        );

        foreach ($terms as $term) {
            $this->assertStringNotContainsStringIgnoringCase('technica', $term);
            $this->assertStringNotContainsStringIgnoringCase('audio', $term);
        }
    }
}
