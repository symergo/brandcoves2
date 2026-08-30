<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Enums\Market;
use App\Services\Editorial\ProseCards;
use App\Services\Guides\CoveMarkup;
use App\Services\Seo\BrandLinker;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Pairing a paragraph with the products it is about.
 *
 * The property this file holds: **the writing decides where a card goes.** A
 * `[[product:12]]` token is the writer saying "this paragraph is about that
 * thing", and the card is rendered underneath it — so everything here is about
 * reading that intent back out without letting a stray token put a product on a
 * page the prose could not link to.
 */
class ProseCardsTest extends TestCase
{
    private function allowed(): array
    {
        return [
            'brands' => ['Sony'],
            'searches' => [],
            'products' => [
                12 => ['slug' => 'sony-wh-1000xm5', 'title' => 'Sony WH-1000XM5'],
                34 => ['slug' => 'jbl-tune', 'title' => 'JBL Tune'],
            ],
        ];
    }

    /**
     * No database, for the reason CoveMarkupTest documents at length: resolving
     * BrandLinker from the container mid-render made a `brand_stats` table a
     * hidden dependency of turning a string into HTML.
     */
    private function cards(): ProseCards
    {
        $markup = new CoveMarkup(new class extends BrandLinker
        {
            public function urls(array $brands, Market $market): array
            {
                return [];
            }
        });

        return new ProseCards($markup, Market::BeNl, $this->allowed());
    }

    #[Test]
    public function a_paragraph_carries_the_products_it_names(): void
    {
        $blocks = $this->cards()->blocks(
            "Een inleiding zonder producten.\n\nDe [[product:12|Sony]] is de stille."
        );

        $this->assertCount(2, $blocks);
        $this->assertSame([], $blocks[0]['groupIds']);
        $this->assertSame([12], $blocks[1]['groupIds']);

        // The html half is still CoveMarkup's, resolved and escaped.
        $this->assertStringContainsString('/be-nl/p/12/sony-wh-1000xm5', $blocks[1]['html']);
    }

    #[Test]
    public function the_first_mention_wins_and_the_second_shows_nothing(): void
    {
        $blocks = $this->cards()->blocks(
            "De [[product:12]] opent.\n\nEn de [[product:12]] nog eens."
        );

        // Copy naturally repeats a name. A second identical card under the
        // second mention reads as a stutter, not as emphasis.
        $this->assertSame([12], $blocks[0]['groupIds']);
        $this->assertSame([], $blocks[1]['groupIds']);
    }

    #[Test]
    public function dedupe_spans_the_whole_document_not_one_block(): void
    {
        $cards = $this->cards();

        $intro = $cards->blocks('De [[product:12]] leidt.');
        $body = $cards->blocks('Terug naar de [[product:12]].');

        /*
         * A guide asks for two blocks of prose — the intro, then the article —
         * and a product introduced up top must not get a second card halfway
         * down. This is the whole reason ProseCards is constructed per page
         * render rather than resolved from the container.
         */
        $this->assertSame([12], $intro[0]['groupIds']);
        $this->assertSame([], $body[0]['groupIds']);
        $this->assertSame([12], $cards->shown());
    }

    #[Test]
    public function a_product_outside_the_allowlist_gets_no_card(): void
    {
        $blocks = $this->cards()->blocks('Kijk ook naar de [[product:999|die andere]].');

        /*
         * CoveMarkup already strips the token back to its label, so the
         * sentence reads. Pairing a card to it anyway would put a product on
         * the page that the prose beside it could not link to — the one state
         * where the reader can see the two disagree.
         */
        $this->assertSame([], $blocks[0]['groupIds']);
        $this->assertStringNotContainsString('<a', $blocks[0]['html']);
        $this->assertStringContainsString('die andere', $blocks[0]['html']);
    }

    #[Test]
    public function two_products_in_one_paragraph_both_land_under_it(): void
    {
        $blocks = $this->cards()->blocks('De [[product:12]] en de [[product:34]].');

        // Allowed, and the prompt contract asks writers not to: the cards stack
        // under the one paragraph and read as a caption for a pair. Rendering
        // only the first would lose the second product from the page entirely,
        // which is worse than an ugly pair.
        $this->assertSame([12, 34], $blocks[0]['groupIds']);
    }

    #[Test]
    public function empty_prose_is_no_blocks_at_all(): void
    {
        $this->assertSame([], $this->cards()->blocks(null));
        $this->assertSame([], $this->cards()->blocks("   \n\n  "));
    }

    #[Test]
    public function the_prompt_contract_states_the_rule_the_walk_enforces(): void
    {
        /*
         * The drift this catches is silent: a contract that stopped asking for
         * a paragraph per product would leave every card at the foot of the
         * page, and nothing would fail — the article would simply read like the
         * catalogue-with-an-introduction the pairing exists to replace.
         */
        $contract = ProseCards::promptContract();

        $this->assertStringContainsString('EVERY product', $contract);
        $this->assertStringContainsString('One product per paragraph', $contract);
    }
}
