<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Enums\Source;
use App\Models\Merchant;
use App\Models\Product;
use App\Services\Catalogue\ProductDescription;
use Illuminate\Support\Collection;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Picking and cleaning the long description.
 *
 * Unsaved models throughout: this is a pure decision over offers the caller has
 * already loaded, and giving it a database would test Eloquent rather than the
 * rules.
 */
class ProductDescriptionTest extends TestCase
{
    private function offer(?string $description, Source $source = Source::Bol, int $id = 1, ?string $shop = 'bol.com'): Product
    {
        $offer = new Product([
            'description' => $description,
            'source' => $source,
        ]);

        $offer->id = $id;

        if ($shop !== null) {
            $offer->setRelation('merchant', new Merchant(['name' => $shop]));
        }

        return $offer;
    }

    #[Test]
    public function it_keeps_list_items_as_separate_paragraphs(): void
    {
        $description = ProductDescription::pick(new Collection([
            $this->offer(
                '<p>Draadloze koptelefoon met actieve ruisonderdrukking voor onderweg.</p>'
                .'<ul><li>Tot 30 uur speelduur op een volle lading</li>'
                .'<li>Bluetooth 5.2 met multipoint-verbinding</li></ul>',
            ),
        ]));

        $this->assertNotNull($description);

        // Not "een ladingBluetooth" — strip_tags alone welds the two bullets
        // into one nonsense word, which is worse than either the markup or
        // nothing at all.
        $this->assertSame([
            'Draadloze koptelefoon met actieve ruisonderdrukking voor onderweg.',
            'Tot 30 uur speelduur op een volle lading',
            'Bluetooth 5.2 met multipoint-verbinding',
        ], $description->paragraphs);

        $this->assertSame('bol.com', $description->merchant);
    }

    #[Test]
    public function it_takes_the_longest_description_not_the_first(): void
    {
        $short = str_repeat('Een korte samenvatting van dit product. ', 4);
        $long = str_repeat('De volledige omschrijving met alle specificaties. ', 8);

        $description = ProductDescription::pick(new Collection([
            $this->offer($short, id: 1, shop: 'Krefel'),
            $this->offer($long, id: 2, shop: 'Coolblue'),
        ]));

        $this->assertNotNull($description);
        $this->assertSame('Coolblue', $description->merchant);
    }

    /** A scrap under a heading looks like a page that failed to load. */
    #[Test]
    public function it_rejects_a_description_too_short_to_earn_a_section(): void
    {
        $this->assertNull(ProductDescription::pick(new Collection([
            $this->offer('Zwart'),
            $this->offer('One size', id: 2),
        ])));
    }

    /** Feeds routinely fill this column with the product name. */
    #[Test]
    public function it_rejects_the_title_repeated(): void
    {
        $title = 'Sony WH-1000XM5 draadloze koptelefoon met ruisonderdrukking zwart';

        $this->assertNull(ProductDescription::pick(
            new Collection([$this->offer('Sony WH 1000XM5 Draadloze Koptelefoon met Ruisonderdrukking, Zwart')]),
            $title,
        ));
    }

    /**
     * Invariant 6. No Amazon row should carry a description at all, so this
     * guards against an upstream bug reaching a page rather than against
     * today's data.
     */
    #[Test]
    public function it_never_quotes_amazon(): void
    {
        $text = str_repeat('Een volledige omschrijving van dit product. ', 5);

        $this->assertNull(ProductDescription::pick(new Collection([
            $this->offer($text, source: Source::Amazon, shop: null),
        ])));

        // The same text from a source that may be stored is fine.
        $this->assertNotNull(ProductDescription::pick(new Collection([
            $this->offer($text, source: Source::Awin, shop: 'Coolblue'),
        ])));
    }

    #[Test]
    public function it_caps_a_runaway_feed_description(): void
    {
        $description = ProductDescription::pick(new Collection([
            $this->offer(str_repeat('Dit is een zin over het product. ', 400)),
        ]));

        $this->assertNotNull($description);

        $length = mb_strlen(implode(' ', $description->paragraphs));
        $this->assertLessThanOrEqual(1800, $length);
        $this->assertGreaterThan(1000, $length);
    }

    #[Test]
    public function it_decodes_entities_and_normalises_whitespace(): void
    {
        $description = ProductDescription::pick(new Collection([
            $this->offer("Koptelefoon &amp; oplaadcase\u{00A0}&mdash;   met   ruisonderdrukking, een reisetui erbij voor onderweg en een kabel voor als de batterij leeg is."),
        ]));

        $this->assertNotNull($description);
        $this->assertSame(
            'Koptelefoon & oplaadcase — met ruisonderdrukking, een reisetui erbij voor onderweg en een kabel voor als de batterij leeg is.',
            $description->paragraphs[0],
        );
    }
}
