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

        // Rescued: the term says consumable, the context says camera. This is
        // the RESCUES table's only job and nothing else exercised it — the
        // polarising filters above survive because no term matches them at all.
        yield 'rescued lens filter' => ['Hoya vervangingsfilter voor lens 77mm', 'Foto', 8999, true, 'ok'];
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

        /*
         * --- What we deliberately stopped catching -----------------------------
         *
         * `spare_part`, `service` and `household_staple` rejected 114 rows
         * between them out of 63,508 classified, and cost three hand-maintained
         * multilingual term lists. Removed 2026-08-29.
         *
         * These stay in the golden file as *passing* rows rather than being
         * deleted, because the cost of the decision is the point: a warranty
         * extension is now a gift as far as this classifier is concerned. If
         * that turns out to matter, this is where the evidence goes.
         */
        yield 'spare part now passes' => ['Bosch reserveonderdeel motorborstel', 'Onderdelen', 2499, true, 'ok'];
        yield 'drill battery now passes' => ['Makita replacement battery 18V 5Ah', 'Gereedschap', 8999, true, 'ok'];
        yield 'warranty now passes' => ['Garantieverlenging 3 jaar', 'Diensten', 4999, true, 'ok'];
        yield 'subscription now passes' => ['Kaspersky abonnement 1 jaar 3 apparaten', 'Software', 3999, true, 'ok'];

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

        // --- Bulk -------------------------------------------------------------

        yield 'bulk pack' => ['Wegwerpbekers 100 stuks', 'Huishouden', 1299, false, 'bulk'];
        yield 'box of fifty' => ['Doos van 50 mondmaskers', 'Gezondheid', 1999, false, 'bulk'];

        /*
         * The staples the term list used to name, now caught structurally.
         *
         * This is why removing `household_staple` was cheap: a staple is always
         * sold by the count, so the count catches it without anybody having to
         * enumerate "toiletpapier, papier toilette, papel higienico" — and it
         * generalises to the ones nobody thought of.
         */
        yield 'toilet paper by the roll' => ['Page toiletpapier 24 rollen', 'Huishouden', 1599, false, 'bulk'];
        yield 'aa batteries by the count' => ['Duracell AA batteries 16 stuks', 'Huishouden', 1899, false, 'bulk'];

        // --- Price band -------------------------------------------------------

        yield 'too cheap' => ['Sleutelhanger', 'Accessoires', 199, false, 'too_cheap'];
        yield 'no price' => ['Sony WH-1000XM5', 'Audio', null, false, 'no_price'];

        /*
         * The split. Not a gift — you do not suggest a €2,499 television to
         * someone asking what to buy their colleague — but absolutely an object
         * worth a slot on a Cove, which is what `worthShowing` is for.
         */
        yield 'too expensive' => ['LG OLED 77 inch televisie', 'TV', 249900, false, 'too_expensive', true];
    }

    /**
     * @param  bool|null  $expectedWorthShowing  defaults to `$expectedGiftable`:
     *                                           only `too_expensive` differs, and
     *                                           spelling that out on 30 rows would
     *                                           bury the one that matters.
     */
    #[Test]
    #[DataProvider('cases')]
    public function it_classifies(
        string $title,
        ?string $category,
        ?int $price,
        bool $expectedGiftable,
        string $expectedReason,
        ?bool $expectedWorthShowing = null,
    ): void {
        $verdict = $this->classifier()->classify($title, $category, $price);

        $this->assertSame(
            $expectedGiftable,
            $verdict->giftable,
            "[$title] expected giftable=".var_export($expectedGiftable, true).
            ', got '.var_export($verdict->giftable, true)." (reason: {$verdict->reason})"
        );

        $this->assertSame($expectedReason, $verdict->reason, "[$title] reason");

        $this->assertSame(
            $expectedWorthShowing ?? $expectedGiftable,
            $verdict->worthShowing,
            "[$title] worthShowing"
        );
    }

    #[Test]
    public function the_price_ceiling_is_the_only_rejection_that_still_shows(): void
    {
        // The invariant behind the split, stated once rather than inferred from
        // the table above: everything else excludes a row from both surfaces.
        $expensive = $this->classifier()->classify('LG OLED 77 inch televisie', 'TV', 249900);
        $consumable = $this->classifier()->classify('HP 305XL inktcartridge zwart', 'Printers', 3299);
        $cheap = $this->classifier()->classify('Sleutelhanger', 'Accessoires', 199);

        $this->assertTrue($expensive->worthShowing, 'over the ceiling is still worth showing');
        $this->assertFalse($consumable->worthShowing, 'a cartridge is not worth showing');
        $this->assertFalse($cheap->worthShowing, 'a €2 keyring is not worth showing');

        foreach ([$expensive, $consumable, $cheap] as $verdict) {
            $this->assertFalse($verdict->giftable);
        }
    }

    #[Test]
    public function accents_are_folded_the_same_way_on_every_platform(): void
    {
        // iconv's //TRANSLIT differs across glibc, musl and Windows. Tests run
        // on a Windows laptop; production is Alpine. A classifier that
        // disagrees with its own test suite by host is worse than none.
        $accented = $this->classifier()->classify('Détartrant pour machine à café', 'Ménage', 2999);
        $plain = $this->classifier()->classify('Detartrant pour machine a cafe', 'Menage', 2999);

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
