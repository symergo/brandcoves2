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
    public function a_product_token_without_a_label_is_never_rendered_as_its_id(): void
    {
        /*
         * The bug this guards, found on production 2026-09-01: three published
         * editions wrote `[[product:6609172]]` and the page read "the 6609172
         * is built for the version on wheels". Linked, escaped and pointed at
         * the right page — only the words a reader sees were a database key.
         *
         * A brand or a search token echoing its value is right, because there
         * the value *is* the words. A product is addressed by id, so it needs
         * the one thing the allowlist can supply: the product's title.
         */
        $result = $this->render('The [[product:1234]] is the obvious one.');

        $this->assertStringContainsString('href="/be-nl/p/1234/sony-wh-1000xm5"', $result['html']);
        $this->assertStringContainsString('>Sony WH-1000XM5</a>', $result['html']);
        $this->assertStringNotContainsString('>1234<', $result['html']);
    }

    #[Test]
    public function plain_text_gives_an_unlabelled_product_its_title_too(): void
    {
        // A meta description, an email, a FAQ answer. Same failure, and the one
        // audience that cannot ask what the number was for.
        $plain = $this->markup()->plain('The [[product:1234]] is the obvious one.', $this->allowed());

        $this->assertSame('The Sony WH-1000XM5 is the obvious one.', $plain);
    }

    #[Test]
    public function the_prompt_contract_demands_a_label(): void
    {
        /*
         * The renderer's fallback is a floor, not the fix. What should reach
         * the page is the writer's own few words for the thing, so the contract
         * has to ask for them — and has to keep asking after somebody tidies it.
         */
        $contract = $this->markup()->promptContract($this->allowed());

        $this->assertStringContainsString('[[product:1234|', $contract);
        $this->assertStringContainsString('MUST carry a label', $contract);
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
    public function an_amazon_token_becomes_a_tagged_link_in_a_market_that_has_a_tag(): void
    {
        config()->set('giftcoves.amazon_search.markets', [
            'be-nl' => ['host' => 'www.amazon.com.be', 'tag' => 'giftcoves05-21'],
        ]);

        $result = $this->render('Kijk ook even [[amazon:draadloze koptelefoon|op Amazon]].');

        $this->assertStringContainsString('https://www.amazon.com.be/s?', $result['html']);
        $this->assertStringContainsString('tag=giftcoves05-21', $result['html']);
        $this->assertStringContainsString('>op Amazon</a>', $result['html']);
        $this->assertSame(1, $result['links']);
    }

    #[Test]
    public function an_amazon_link_is_marked_sponsored(): void
    {
        /*
         * An affiliate link a search engine cannot tell from an editorial one
         * is the kind of thing that costs a site its rankings. `nofollow`
         * alone no longer says what this is.
         */
        config()->set('giftcoves.amazon_search.markets', [
            'be-nl' => ['host' => 'www.amazon.com.be', 'tag' => 'giftcoves05-21'],
        ]);

        $html = $this->render('[[amazon:koptelefoon]]')['html'];

        $this->assertStringContainsString('rel="sponsored nofollow noopener"', $html);
        $this->assertStringContainsString('target="_blank"', $html);
    }

    #[Test]
    public function a_market_with_no_associates_tag_gets_no_amazon_link_at_all(): void
    {
        /*
         * THE OTHER TEST THIS FILE EXISTS FOR, and the whole reason the token
         * resolves through AmazonSearchLink rather than reading config itself.
         *
         * `en` and `es` have no tag issued. Sending a reader to a storefront
         * under nobody's tag is unattributed traffic that looks exactly like
         * working traffic — so the sentence loses its link and keeps its words,
         * which is a visible absence rather than a silent leak.
         *
         * This is also what implements "only nl and be" without a market list
         * anywhere in the content.
         */
        config()->set('giftcoves.amazon_search.markets', [
            'be-nl' => ['host' => 'www.amazon.com.be', 'tag' => 'giftcoves05-21'],
        ]);

        $result = $this->markup()->render(
            'Have a look [[amazon:wireless headphones|on Amazon]] too.',
            Market::En,
            $this->allowed(),
        );

        $this->assertStringNotContainsString('<a', $result['html']);
        $this->assertStringNotContainsString('amazon.', $result['html']);
        $this->assertStringNotContainsString('[[', $result['html']);
        $this->assertStringContainsString('on Amazon', $result['html']);
        $this->assertSame(0, $result['links']);
    }

    #[Test]
    public function an_amazon_token_is_reduced_to_its_label_in_plain_text(): void
    {
        // A meta description or an email must never print the token, and must
        // never print a URL either.
        $plain = $this->markup()->plain('Kijk [[amazon:koptelefoon|op Amazon]] voor meer.');

        $this->assertSame('Kijk op Amazon voor meer.', $plain);
    }

    /**
     * The one piece of Markdown this renderer honours.
     *
     * Model output arrived carrying `**bold**` and the page printed the
     * asterisks, because prose here is escaped and then walked for link tokens
     * and nothing else. Two characters were the whole gap between a sentence an
     * advice article wanted the reader to take away and a line that looked
     * like a bug.
     */
    #[Test]
    public function bold_markup_becomes_strong(): void
    {
        $result = $this->render('Let op: **er is geen vaste termijn.** Dat scheelt.');

        $this->assertStringContainsString('<strong>er is geen vaste termijn.</strong>', $result['html']);
        $this->assertStringNotContainsString('**', $result['html']);
    }

    #[Test]
    public function bold_may_wrap_a_link(): void
    {
        // The shape a writer actually produces. Emphasis is resolved after the
        // tokens, so the anchor is intact inside the <strong>.
        $result = $this->render('**Kies de [[brand:Sony]].**');

        $this->assertSame(
            '<strong>Kies de <a href="/be-nl/search?brand%5B0%5D=Sony">Sony</a>.</strong>',
            $result['html'],
        );
        $this->assertSame(1, $result['links']);
    }

    #[Test]
    public function nothing_else_is_treated_as_markdown(): void
    {
        /*
         * Bold is honoured because it is what the models write. Every other
         * Markdown character is a syntax a feed's product title can contain by
         * accident, and a renderer that grows a rule per character ends up
         * interpreting markup we did not write.
         */
        $result = $this->render('# Not a heading, _not italics_ and [not a link](http://x.test).');

        $this->assertStringNotContainsString('<h1', $result['html']);
        $this->assertStringNotContainsString('<em', $result['html']);
        $this->assertStringNotContainsString('<a', $result['html']);
    }

    #[Test]
    public function a_stray_asterisk_is_left_alone(): void
    {
        // "5*" is not markup, and an unclosed pair is a typo rather than an
        // instruction. Both must survive as written.
        $this->assertStringContainsString('5* by buyers', $this->render('rated 5* by buyers')['html']);
    }

    #[Test]
    public function plain_text_keeps_the_words_and_drops_the_asterisks(): void
    {
        // A <meta> description and a FAQPage answer have nothing to render
        // emphasis with, and the one audience that reads them cannot ask what
        // the asterisks were for.
        $this->assertSame(
            'Er is geen vaste termijn.',
            $this->markup()->plain('**Er is geen vaste termijn.**'),
        );
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

        // Said out loud so a writer does not reach for `#`, `_` or a list and
        // get literal characters on the page.
        $this->assertStringContainsString('**bold** renders', $contract);

        /*
         * And `amazon` is deliberately NOT offered to the model.
         *
         * That token is a paid link out of the site. Which sentences carry a
         * commercial hand-off is an editorial judgement, and it is not one to
         * delegate to something that is simultaneously being asked to sound
         * helpful. Authored Coves use it; generated ones do not.
         */
        $this->assertStringNotContainsString('[[amazon:', $contract);
    }
}
