<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\IdentityKind;
use App\Enums\Market;
use App\Enums\Source;
use App\Jobs\GroupProducts;
use App\Jobs\IngestFeed;
use App\Models\Feed;
use App\Models\Product;
use App\Models\ProductGroup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Advertisers disagree about which column holds the barcode, and Awin does not
 * normalise them.
 *
 * Measured on the live feeds: Krefel fills `ean` and leaves `upc` empty;
 * Coolblue fills `upc` and leaves `ean` empty. Reading only `ean` put the two
 * shops in separate identity spaces — EAN-grouped versus title-grouped — and
 * produced ZERO comparable products despite 1,955 shared barcodes.
 *
 * This is the difference between the site's core feature working and not.
 */
class BarcodeColumnTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $feed = Feed::create([
            'source' => Source::Awin,
            'external_feed_id' => 'mixed',
            'market' => Market::BeNl,
            'label' => 'Mixed barcode columns',
            'enabled' => true,
            'column_map' => ['url' => base_path('tests/Fixtures/awin-mixed-barcode-columns.csv')],
        ]);

        IngestFeed::dispatchSync($feed->id);
        GroupProducts::dispatchSync(Market::BeNl);
    }

    #[Test]
    public function a_barcode_in_upc_is_read_just_like_one_in_ean(): void
    {
        $coolblue = Product::query()->where('external_id', '2001')->firstOrFail();
        $krefel = Product::query()->where('external_id', '2002')->firstOrFail();

        $this->assertSame('4006381333931', $coolblue->identity_key, 'upc column must be read');
        $this->assertSame('4006381333931', $krefel->identity_key, 'ean column must be read');
        $this->assertSame(IdentityKind::Ean, $coolblue->identity_kind);
        $this->assertSame(IdentityKind::Ean, $krefel->identity_kind);
    }

    #[Test]
    public function product_gti_n_is_read_too(): void
    {
        $this->assertSame(
            '8719514339149',
            Product::query()->where('external_id', '2003')->value('identity_key'),
        );
    }

    #[Test]
    public function shops_using_different_columns_still_become_one_comparable_product(): void
    {
        // The whole point. Two shops, two column conventions, one product.
        $group = ProductGroup::query()->where('identity_key', '4006381333931')->firstOrFail();

        $this->assertSame(2, $group->merchant_count);
        $this->assertSame(32999, $group->min_price);
        $this->assertSame(34900, $group->max_price);

        $hue = ProductGroup::query()->where('identity_key', '8719514339149')->firstOrFail();
        $this->assertSame(2, $hue->merchant_count);
    }

    #[Test]
    public function a_part_number_in_the_upc_column_is_not_mistaken_for_a_barcode(): void
    {
        // "BBVSPS5001" is a manufacturer part number. Each candidate column is
        // validated rather than trusted, so it falls through to title identity
        // instead of becoming a bogus key that merges unrelated products.
        $product = Product::query()->where('external_id', '2005')->firstOrFail();

        $this->assertNotSame('BBVSPS5001', $product->identity_key);
        $this->assertSame(IdentityKind::Title, $product->identity_kind);
    }

    #[Test]
    public function a_upc_a_barcode_is_normalised_to_gtin13(): void
    {
        // 12-digit UPC-A zero-pads to 13, so a shop quoting UPC-A and a shop
        // quoting EAN-13 for the same product still land on one key.
        $this->assertSame(
            '0036000291452',
            Product::query()->where('external_id', '2006')->value('identity_key'),
        );
    }
}
