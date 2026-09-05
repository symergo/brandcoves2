<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\ProductGroup;
use App\Services\Catalogue\ProductTitle;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Cleaning a merchant's title into a heading and a listing.
 *
 * Unsaved models throughout: this is a pure decision over a row the caller has
 * already loaded, and every example below is a real string from the production
 * catalogue rather than one invented to suit the rules.
 */
class ProductTitleTest extends TestCase
{
    private function group(string $title, ?string $brand = null, int $merchants = 1): ProductGroup
    {
        return (new ProductGroup)->forceFill([
            'title' => $title,
            'brand' => $brand,
            'merchant_count' => $merchants,
        ]);
    }

    #[Test]
    public function a_shouting_title_is_title_cased(): void
    {
        $this->assertSame(
            'JBL Tuner 3 Bluetooth Black',
            ProductTitle::heading($this->group('JBL TUNER 3 BLUETOOTH BLACK', 'JBL')),
        );
    }

    /**
     * A title that is merely capitalised is not shouting, and rewriting it
     * would be inventing a house style the feed never agreed to.
     */
    #[Test]
    public function a_normally_cased_title_is_left_exactly_as_it_is(): void
    {
        $title = 'JBL Charge 6 Draadloze Bluetooth Speaker met Ingebouwde Powerbank Zwart';

        $this->assertSame($title, ProductTitle::heading($this->group($title, 'JBL')));
    }

    /**
     * Model numbers and capacities carry their own casing, and title-casing
     * them produces "512Gb" and "Wh-1000Xm5".
     */
    #[Test]
    public function tokens_with_digits_keep_their_casing(): void
    {
        $this->assertSame(
            'Sony WH-1000XM5 Draadloze Koptelefoon 512GB',
            ProductTitle::heading($this->group('SONY WH-1000XM5 DRAADLOZE KOPTELEFOON 512GB', 'Sony')),
        );
    }

    #[Test]
    public function known_acronyms_are_not_lowercased(): void
    {
        // "Met" rather than "met": an ALL-CAPS source carries no information
        // about which words were meant to be small, and a list of function
        // words in four languages would be guessing at it. Title Case is the
        // convention for a product name and it is what the feed gets.
        $this->assertSame(
            'Anker USB-C Kabel Met LED Indicator',
            ProductTitle::heading($this->group('ANKER USB-C KABEL MET LED INDICATOR', 'Anker')),
        );
    }

    /**
     * A brand of two or three capitals is a brand, not a feed shouting. Without
     * the four-letter run in the test, "LG TV" would come back as "Lg Tv".
     */
    #[Test]
    public function a_short_all_caps_title_is_not_treated_as_shouting(): void
    {
        $this->assertSame('LG TV', ProductTitle::heading($this->group('LG TV', 'LG')));
    }

    #[Test]
    public function a_missing_brand_is_put_in_front(): void
    {
        $this->assertSame(
            'JBL Tune 530 koptelefoon - Zwart',
            ProductTitle::heading($this->group('Tune 530 koptelefoon - Zwart', 'JBL')),
        );
    }

    /**
     * The two spellings are one brand, which is why the comparison is on slugs.
     * A `str_contains` on the raw strings prefixes "Audio-Technica" onto a title
     * that already says "Audio Technica".
     */
    #[Test]
    public function a_brand_already_present_under_another_spelling_is_not_repeated(): void
    {
        $this->assertSame(
            'Audio Technica ATH-M50x',
            ProductTitle::heading($this->group('Audio Technica ATH-M50x', 'Audio-Technica')),
        );
    }

    #[Test]
    public function a_group_with_no_brand_is_left_alone(): void
    {
        $this->assertSame(
            'Draadloze oordopjes',
            ProductTitle::heading($this->group('Draadloze oordopjes', null)),
        );
    }

    /** The heading is what an h1 shows, and an h1 has the width of the page. */
    #[Test]
    public function the_heading_is_never_truncated(): void
    {
        $long = str_repeat('Bluetooth speaker met powerbank ', 6);

        $this->assertSame(trim($long), ProductTitle::heading($this->group(trim($long))));
    }

    #[Test]
    public function the_listing_carries_the_shop_count_when_there_is_more_than_one(): void
    {
        $this->assertSame(
            'Sony SRS-XB100 Zwart — at 5 shops',
            ProductTitle::listing($this->group('Sony SRS-XB100 Zwart', 'Sony', merchants: 5)),
        );
    }

    /** One seller is not a comparison, and saying "at 1 shop" advertises that. */
    #[Test]
    public function the_listing_omits_the_count_for_a_single_seller(): void
    {
        $this->assertSame(
            'Sony SRS-XB100 Zwart',
            ProductTitle::listing($this->group('Sony SRS-XB100 Zwart', 'Sony', merchants: 1)),
        );
    }

    /**
     * The whole point: 85.4% of `en` product titles were longer than a listing
     * shows, at a median of 121 characters.
     */
    #[Test]
    public function a_long_listing_title_is_cut_to_fit_a_search_result(): void
    {
        $listing = ProductTitle::listing($this->group(
            'JBL Charge 6 Draadloze Bluetooth Speaker met Ingebouwde Powerbank Zwart',
            'JBL',
            merchants: 5,
        ));

        // 48 is what remains of the ~60 a listing shows once " · GiftCoves" is
        // appended by app.tsx.
        $this->assertLessThanOrEqual(48, mb_strlen($listing), $listing);
        $this->assertStringStartsWith('JBL Charge 6', $listing);
        $this->assertStringContainsString('5', $listing);
    }

    /** Cut at a separator, so the title does not end mid-word. */
    #[Test]
    public function a_cut_title_ends_on_a_word(): void
    {
        $listing = ProductTitle::listing($this->group(
            'Philips Hue White and Color Ambiance Starterspakket E27 met Bridge',
            'Philips',
            merchants: 3,
        ));

        $this->assertDoesNotMatchRegularExpression('/\w—/u', $listing);
        $this->assertStringNotContainsString('  ', $listing);
    }

    /**
     * Both defects on one row, which is the common case in the Belgian markets:
     * a shouting title from a feed that also omits the brand.
     */
    #[Test]
    public function shouting_and_a_missing_brand_are_fixed_together(): void
    {
        $this->assertSame(
            'Philips Tuner 3 Bluetooth Black',
            ProductTitle::heading($this->group('TUNER 3 BLUETOOTH BLACK', 'Philips')),
        );
    }
}
