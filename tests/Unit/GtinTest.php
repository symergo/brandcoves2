<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Identity\Gtin;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The strictness here is deliberate. A wrong merge shows a shopper "3 offers"
 * for something only one shop sells, with a "cheapest" price belonging to a
 * different product. Failing to merge only costs a comparison.
 */
class GtinTest extends TestCase
{
    #[Test]
    #[DataProvider('realBarcodes')]
    public function it_accepts_real_barcodes(string $raw, string $expected): void
    {
        $this->assertSame($expected, Gtin::normalise($raw));
    }

    public static function realBarcodes(): array
    {
        return [
            // Genuine EAN-13s with correct check digits.
            'EAN-13 book' => ['9780306406157', '9780306406157'],
            'EAN-13 product' => ['4006381333931', '4006381333931'],
            // UPC-A zero-pads to 13; the check digit is unchanged.
            'UPC-A' => ['036000291452', '0036000291452'],
            // Feeds pad with spaces and hyphens.
            'spaces' => ['  4006381333931 ', '4006381333931'],
            'hyphens' => ['4-006381-333931', '4006381333931'],
        ];
    }

    #[Test]
    #[DataProvider('rejected')]
    public function it_rejects_unusable_values(?string $raw, string $why): void
    {
        $this->assertNull(Gtin::normalise($raw), $why);
    }

    public static function rejected(): array
    {
        return [
            'null' => [null, 'no value'],
            'empty' => ['', 'no value'],
            'whitespace' => ['   ', 'no value'],
            'zero' => ['0', 'the most common feed placeholder'],
            'N/A' => ['N/A', 'placeholder'],
            'none' => ['none', 'placeholder'],
            'geen' => ['geen', 'Dutch placeholder'],
            'wrong check digit' => ['4006381333932', 'fails GS1 mod-10'],
            'too short' => ['12345', 'not a GTIN length'],
            'too long' => ['123456789012345', 'not a GTIN length'],
            'letters only' => ['ABCDEFGHIJKLM', 'no digits'],
            // Passes the check digit but is meaningless filler.
            'all zeros' => ['0000000000000', 'degenerate'],
            'repeated digit' => ['2222222222222', 'degenerate'],
        ];
    }

    #[Test]
    public function itf14_of_a_single_unit_unwraps_to_the_consumer_gtin(): void
    {
        // Indicator 0 means "the consumer unit itself", so it is the same
        // physical product and may be merged.
        $itf14 = '0'.'400638133393'.Gtin::computeCheckDigit('0400638133393');

        $this->assertSame('4006381333931', Gtin::normalise($itf14));
    }

    #[Test]
    public function itf14_of_a_carton_is_rejected(): void
    {
        // Indicator 1-8 means a case of N units. That is a different product at
        // a different price — merging it would put a case of 24 alongside a
        // single item as if they were competing offers on the same thing.
        $carton = '5'.'400638133393'.Gtin::computeCheckDigit('5400638133393');

        $this->assertNull(Gtin::normalise($carton), 'a carton is not the consumer unit');
    }

    #[Test]
    public function restricted_distribution_prefixes_are_rejected(): void
    {
        // 02x and 04x are shop-internal codes. Two retailers can legitimately
        // assign the same one to entirely different products, which is exactly
        // the merge that must not happen.
        $internal = '020123456789'.Gtin::computeCheckDigit('020123456789');
        $this->assertNull(Gtin::normalise($internal));

        $inStore = '040123456789'.Gtin::computeCheckDigit('040123456789');
        $this->assertNull(Gtin::normalise($inStore));
    }

    #[Test]
    public function the_check_digit_algorithm_matches_gs1(): void
    {
        // Worked example from the GS1 specification: the weighted sum over
        // 629104150021 is 57, so the check digit is (10 - 7) % 10 = 3.
        $this->assertSame('3', Gtin::computeCheckDigit('629104150021'));
        $this->assertTrue(Gtin::hasValidCheckDigit('6291041500213'));
        $this->assertFalse(Gtin::hasValidCheckDigit('6291041500214'));
    }

    #[Test]
    public function a_single_digit_error_is_always_caught(): void
    {
        // The whole point of a check digit. Vary each position in turn and
        // confirm every corruption is rejected.
        $valid = '4006381333931';

        for ($i = 0; $i < 13; $i++) {
            $corrupted = $valid;
            $corrupted[$i] = (string) (((int) $valid[$i] + 1) % 10);

            $this->assertFalse(
                Gtin::hasValidCheckDigit($corrupted),
                "corrupting position {$i} should be detected",
            );
        }
    }

    #[Test]
    public function is_valid_agrees_with_normalise(): void
    {
        $this->assertTrue(Gtin::isValid('4006381333931'));
        $this->assertFalse(Gtin::isValid('0'));
    }
}
