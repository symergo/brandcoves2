<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Gift\GiftabilityClassifier;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Golden file for the giftability classifier.
 *
 * Every row here is a real shape seen in the Awin feeds. The list is the
 * specification: when the classifier changes, this file says what changed and
 * whether it was meant to.
 *
 * Plain PHPUnit\TestCase, not Laravel's — the classifier takes no framework
 * dependency and the test should not either. A test that boots the container to
 * check a pure function hides the fact that it is pure.
 */
class GiftabilityClassifierTest extends TestCase
{
    private function classifier(): GiftabilityClassifier
    {
        return new GiftabilityClassifier(minPrice: 500, maxPrice: 50000);
    }

    /** @return iterable<string, array{string, string|null, int, bool, string}> */
    public static function cases(): iterable
    {
        // --- Things that are gifts -------------------------------------------

        yield 'headphones' => ['Sony WH-1000XM5 draadloze koptelefoon', 'Audio', 32999, true, 'ok'];
        yield 'board game' => ['Catan bordspel', 'Speelgoed', 3999, true, 'ok'];
        yield 'espresso machine' => ['De Longhi Dedica espressomachine', 'Keuken', 19999, true, 'ok'];
        yield 'perfume' => ['Chanel Bleu de Chanel eau de parfum 100ml', 'Beauty', 11500, true, 'ok'];

        /*
         * THE TRAP. A polarising filter is a real present for someone who
         * photographs, and it contains the word that disqualifies a vacuum
         * cleaner spare. It survives because the disqualifying list holds
         * compounds ("waterfilter"), never the bare stem.
         */
        yield 'camera polarising filter' => ['Hoya polarisatiefilter 77mm', 'Foto', 8999, true, 'ok'];
        yield 'camera ND filter' => ['NiSi ND-filter 82mm', 'Foto', 12900, true, 'ok'];
        yield 'lens filter set' => ['K&F Concept lensfilter set UV CPL', 'Foto', 5999, true, 'ok'];

        // Rescued: the word says spare, the context says present.
        yield 'power bank' => ['Anker replacement battery powerbank 20000mAh', 'Accessoires', 5999, true, 'ok'];
        // Regression: "navulling" and "navul" both matched, and the shorter
        // term — reached second, carrying no rescue — overturned the first
        // one's. A term must never sit in the list beside its own prefix.
        yield 'coffee gift hamper' => ['Koffie cadeaupakket met navulling en beker', 'Voeding', 3499, true, 'ok'];

        // A three-piece set is a product; only double-digit counts read as bulk.
        yield 'small set is not bulk' => ['Le Creuset 3-delige pannenset', 'Keuken', 24999, true, 'ok'];

        // --- Consumables ------------------------------------------------------

        /*
         * Dutch closes its compounds, so a word-boundary matcher sees one
         * unknown token and lets these through. This is the reason the
         * classifier matches substrings at all.
         */
        yield 'dutch compound: ink cartridge' => ['HP 305XL inktcartridge zwart', 'Printers', 3299, false, 'consumable'];
        yield 'dutch compound: vacuum bags' => ['Miele stofzuigerzakken type GN', 'Huishouden', 1899, false, 'consumable'];
        yield 'dutch compound: water filter' => ['Brita Maxtra waterfilter 6-pack', 'Keuken', 2499, false, 'consumable'];
        yield 'dutch compound: extractor filter' => ['Afzuigkapfilter koolstof universeel', 'Keuken', 1499, false, 'consumable'];
        yield 'descaler' => ['De Longhi ontkalker 500ml', 'Keuken', 999, false, 'consumable'];
        yield 'french detergent' => ['Ariel lessive liquide concentrée', 'Ménage', 1299, false, 'consumable'];
        yield 'toner' => ['Brother TN-2420 toner', 'Printers', 5999, false, 'consumable'];

        // --- Spare parts ------------------------------------------------------

        yield 'spare part' => ['Bosch reserveonderdeel motorborstel', 'Onderdelen', 2499, false, 'spare_part'];
        yield 'drill battery' => ['Makita replacement battery 18V 5Ah', 'Gereedschap', 8999, false, 'spare_part'];

        // --- Services ---------------------------------------------------------

        yield 'extended warranty' => ['Garantieverlenging 3 jaar', 'Diensten', 4999, false, 'service'];
        yield 'subscription' => ['Kaspersky abonnement 1 jaar 3 apparaten', 'Software', 3999, false, 'service'];
        yield 'installation' => ['Installatieservice wasmachine', 'Diensten', 8900, false, 'service'];

        // --- Fitment ----------------------------------------------------------

        /*
         * The honest signal. A case for one specific handset is a fine gift
         * only if you already know which handset they carry — and someone using
         * a gift finder does not.
         */
        yield 'phone case' => ['Spigen hoesje voor iPhone 15 Pro', 'Accessoires', 2499, false, 'fitment'];
        yield 'screen protector' => ['Screenprotector Samsung Galaxy S24', 'Accessoires', 1499, false, 'fitment'];
        // Caught as fitment, not as a consumable: "compatible with" is the
        // decisive phrase and "inkt" alone is not a disqualifying term. Either
        // reason excludes it; recording which one keeps admin honest.
        yield 'compatible ink' => ['Inkt compatible with Canon PG-540', 'Printers', 1999, false, 'fitment'];

        // --- Staples ----------------------------------------------------------

        yield 'toilet paper' => ['Page toiletpapier 24 rollen', 'Huishouden', 1599, false, 'household_staple'];
        yield 'aa batteries' => ['Duracell AA batteries 16 stuks', 'Huishouden', 1899, false, 'household_staple'];

        // --- Bulk -------------------------------------------------------------

        yield 'bulk pack' => ['Wegwerpbekers 100 stuks', 'Huishouden', 1299, false, 'bulk'];
        yield 'box of fifty' => ['Doos van 50 mondmaskers', 'Gezondheid', 1999, false, 'bulk'];

        // --- Price band -------------------------------------------------------

        yield 'too cheap' => ['Sleutelhanger', 'Accessoires', 199, false, 'too_cheap'];
        yield 'too expensive' => ['LG OLED 77 inch televisie', 'TV', 249900, false, 'too_expensive'];
        yield 'no price' => ['Sony WH-1000XM5', 'Audio', null, false, 'no_price'];
    }

    #[Test]
    #[DataProvider('cases')]
    public function it_classifies(
        string $title,
        ?string $category,
        ?int $price,
        bool $expectedGiftable,
        string $expectedReason,
    ): void {
        $verdict = $this->classifier()->classify($title, $category, $price);

        $this->assertSame(
            $expectedGiftable,
            $verdict->giftable,
            "[$title] expected giftable=".var_export($expectedGiftable, true).
            ', got '.var_export($verdict->giftable, true)." (reason: {$verdict->reason})"
        );

        $this->assertSame($expectedReason, $verdict->reason, "[$title] reason");
    }

    #[Test]
    public function accents_are_folded_the_same_way_on_every_platform(): void
    {
        // iconv's //TRANSLIT differs across glibc, musl and Windows. Tests run
        // on a Windows laptop; production is Alpine. A classifier that
        // disagrees with its own test suite by host is worse than none.
        $accented = $this->classifier()->classify('Pièce de rechange moteur', 'Pièces', 2999);
        $plain = $this->classifier()->classify('Piece de rechange moteur', 'Pieces', 2999);

        $this->assertFalse($accented->giftable);
        $this->assertSame($plain->reason, $accented->reason);
    }

    #[Test]
    public function the_evidence_says_which_phrase_decided_it(): void
    {
        // Stored on the row so "why is this not in the gift results" is
        // answerable in admin without re-running the pass over 70,000 rows.
        $verdict = $this->classifier()->classify('HP 305XL inktcartridge zwart', 'Printers', 3299);

        $this->assertSame('cartridge', $verdict->evidence);
    }

    #[Test]
    public function an_empty_title_is_not_a_gift(): void
    {
        $this->assertFalse($this->classifier()->classify('', null, 2999)->giftable);
    }
}
