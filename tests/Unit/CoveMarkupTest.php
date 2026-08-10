<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Enums\Market;
use App\Services\Guides\CoveMarkup;
use App\Services\Seo\BrandLinker;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Link tokens in a Cove's prose.
 *
 * The safety property this file exists to hold: **the model chooses emphasis,
 * we choose destinations.** Asked for links, a language model produces
 * confident, well-formed, entirely fictional ones. Every one is a 404 in the
 * middle of an article, and at generation scale that is a self-inflicted crawl
 * problem.
 */
class CoveMarkupTest extends TestCase
{
    private function allowed(): array
    {
        return [
            'brands' => ['Sony', 'JBL'],
            'searches' => ['draadloze koptelefoon'],
            'products' => [1234 => ['slug' => 'sony-wh-1000xm5', 'title' => 'Sony WH-1000XM5']],
        ];
    }

    /**
     * A brand linker that answers from memory.
     *
     * This test is about token parsing and destination allowlisting, neither of
     * which needs a database. `CoveMarkup` used to resolve `BrandLinker` from
     * the container mid-render, which made every case here depend on a
     * `brand_stats` table — so the file passed on its own and errored on all 11
     * cases in a full run, depending on what an earlier test had left behind.
     *
     * Empty is the honest answer for a class with no brand pages: `brand()`
     * falls back to a filtered search link, which is exactly what these tests
     * assert. Whether a brand *has* a page belongs to BrandPageTest.
     */
    private function markup(): CoveMarkup
    {
        return new CoveMarkup(new class extends BrandLinker
        {
            public function urls(array $brands, Market $market): array
            {
                return [];
            }
        });
    }

    private function render(string $text): array
    {
        return $this->markup()->render($text, Market::BeNl, $this->allowed());
    }

    #[Test]
    public function an_allowed_brand_becomes_a_filtered_search_link(): void
    {
        $result = $this->render('We like [[brand:Sony]] a lot.');

        $this->assertStringContainsString('href="/be-nl/search?brand%5B0%5D=Sony"', $result['html']);
        $this->assertStringContainsString('>Sony</a>', $result['html']);
        $this->assertSame(1, $result['links']);
    }

    #[Test]
    public function a_brand_matches_regardless_of_capitalisation(): void
    {
        // The model will not reproduce a feed's capitalisation, and "sony"
        // meaning Sony is not a hallucination.
        $this->assertSame(1, $this->render('Try [[brand:sony]].')['links']);
    }

    #[Test]
    public function a_product_token_links_to_the_product_page(): void
    {
        $result = $this->render('The [[product:1234|XM5]] is the obvious one.');

        $this->assertStringContainsString('href="/be-nl/p/1234/sony-wh-1000xm5"', $result['html']);
        // The label is the model's, the destination is ours.
        $this->assertStringContainsString('>XM5</a>', $result['html']);
    }

    #[Test]
    public function an_invented_brand_degrades_to_plain_text(): void
    {
        /*
         * THE TEST THIS FILE EXISTS FOR.
         *
         * A brand we do not carry must not become a link, and must not stay a
         * visible token either. The sentence still reads; nobody is sent
         * anywhere that does not exist.
         */
        $result = $this->render('Also consider [[brand:Bang & Olufsen]] here.');

        $this->assertStringNotContainsString('<a', $result['html']);
        $this->assertStringNotContainsString('[[', $result['html']);
        $this->assertStringContainsString('Bang &amp; Olufsen', $result['html']);
        $this->assertSame(['brand:Bang & Olufsen'], $result['rejected']);
    }

    #[Test]
    public function an_invented_product_id_degrades_to_its_label(): void
    {
        $result = $this->render('Try the [[product:999999|Imaginary 9000]].');

        $this->assertStringNotContainsString('<a', $result['html']);
        $this->assertStringContainsString('Imaginary 9000', $result['html']);
    }

    #[Test]
    public function a_search_token_must_match_the_allowlist(): void
    {
        $good = $this->render('See [[search:draadloze koptelefoon]].');
        $this->assertSame(1, $good['links']);

        // An arbitrary phrase would be a link to a results page we have never
        // checked returns anything — a thin page we generated on purpose.
        $bad = $this->render('See [[search:iets heel anders]].');
        $this->assertSame(0, $bad['links']);
    }

    #[Test]
    public function model_output_cannot_inject_markup(): void
    {
        /*
         * The prose is model output and is rendered as HTML. Escaping happens
         * before token resolution, so anything that arrives already looking
         * like markup stops being markup before we add our own.
         */
        $result = $this->render('<script>alert(1)</script> and [[brand:Sony]]');

        $this->assertStringNotContainsString('<script>', $result['html']);
        $this->assertStringContainsString('&lt;script&gt;', $result['html']);
        // Our own link still renders.
        $this->assertSame(1, $result['links']);
    }

    #[Test]
    public function a_label_cannot_carry_markup_either(): void
    {
        $result = $this->render('[[brand:Sony|<b>Sony</b>]]');

        $this->assertStringNotContainsString('<b>', $result['html']);
        $this->assertStringContainsString('&lt;b&gt;', $result['html']);
    }

    #[Test]
    public function an_unknown_token_kind_is_left_alone(): void
    {
        // Only the three documented kinds are resolved. Anything else is not a
        // link and not an error — the pattern simply does not match it.
        $result = $this->render('[[category:Audio]]');

        $this->assertStringNotContainsString('<a', $result['html']);
    }

    #[Test]
    public function paragraphs_are_split_on_blank_lines(): void
    {
        $result = $this->markup()->paragraphs(
            "First, about [[brand:Sony]].\n\nSecond, about [[brand:JBL]].",
            Market::BeNl,
            $this->allowed(),
        );

        $this->assertCount(2, $result['html']);
        $this->assertSame(2, $result['links']);
    }

    #[Test]
    public function the_prompt_contract_lists_only_what_the_renderer_accepts(): void
    {
        $contract = $this->markup()->promptContract($this->allowed());

        /*
         * The prompt lives next to the parser because the two drifting apart is
         * silent: the model keeps writing tokens and they quietly stop becoming
         * links. If this assertion ever needs changing, the renderer changed.
         */
        foreach (['[[brand:', '[[search:', '[[product:', 'Sony', 'draadloze koptelefoon'] as $needle) {
            $this->assertStringContainsString($needle, $contract);
        }

        $this->assertStringContainsString('Never write a URL', $contract);
    }
}
