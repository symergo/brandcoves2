<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\Availability;
use App\Enums\Market;
use App\Enums\Source;
use App\Models\AmazonProduct;
use App\Models\ApiToken;
use App\Models\Merchant;
use App\Models\ProductGroup;
use App\Services\Connectors\Offer;
use App\Services\Ingestion\OfferUpserter;
use App\Services\Ingestion\ProductGrouper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Recording a page of Amazon products from the browser extension.
 *
 * Amazon is the source with the tightest rules on this site, so the tests that
 * matter are mostly about what must NOT happen:
 *
 * 1. **Nothing reaches the catalogue.** `Source::allowsCatalogueStorage()` is
 *    false for Amazon and stays false. An import writes to `amazon_products`
 *    and never to `products`, so Amazon cannot appear in search, in offer
 *    comparison, on a chart, in a wishlist or in an email.
 * 2. **No price, by any route.** There is no field for one and no column for
 *    one. This is the field the Associates agreement binds to a 24-hour
 *    refresh, and the owner asked for everything except it.
 * 3. **The barcode is the point.** It fills `identity_key`, which is the
 *    documented bridge from an ASIN to the product group other shops' offers
 *    hang off — and which nothing has ever been able to fill.
 *
 * See docs/features/amazon-compliance.md.
 */
class AmazonPageImportTest extends TestCase
{
    use RefreshDatabase;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $this->token = ApiToken::issue('extension', [ApiToken::READ, ApiToken::WRITE])['token'];
    }

    /** @param list<array<string, mixed>> $products */
    private function importPage(array $products, string $locale = 'amazon.nl'): TestResponse
    {
        return $this->withToken($this->token)->postJson('/api/editorial/import/amazon', [
            'locale' => $locale,
            'products' => $products,
        ]);
    }

    /**
     * A product page's worth of fields, as the scraper actually returns them.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function product(array $overrides = []): array
    {
        return array_merge([
            'asin' => 'B0BTJD6LCL',
            'title' => 'Sony WH-CH520 Draadloze Bluetooth-hoofdtelefoon',
            'description' => 'De Digital Sound Enhancement Engine herstelt je muziek.',
            'imageUrl' => 'https://m.media-amazon.com/images/I/71e6EQRCsL._AC_SY879_.jpg',
            'category' => 'On-ear-koptelefoons',
            'brand' => 'Sony',
            'ean' => null,
            'url' => 'https://www.amazon.nl/dp/B0BTJD6LCL',
        ], $overrides);
    }

    // ── Nothing reaches the catalogue ─────────────────────────────────────

    #[Test]
    public function an_import_writes_the_decision_store_and_never_the_catalogue(): void
    {
        $this->importPage([$this->product()])
            ->assertOk()
            ->assertJsonPath('imported', 1)
            ->assertJsonPath('results.0.status', 'imported');

        $product = AmazonProduct::query()->sole();

        $this->assertSame('B0BTJD6LCL', $product->asin);
        $this->assertSame('Sony', $product->brand);
        $this->assertSame('On-ear-koptelefoons', $product->category);
        $this->assertStringContainsString('Digital Sound', (string) $product->description);
        $this->assertSame('amazon.nl', $product->classified_locale);
        $this->assertSame(['amazon.nl'], $product->seen_in_locales);

        /*
         * The line this whole feature sits against. An Amazon row in `products`
         * would be in the search index, in offer comparison, in the Awin-shaped
         * grouping and eventually in an email — every one of them a separate
         * clause of the Associates agreement.
         */
        $this->assertDatabaseCount('products', 0);
        $this->assertDatabaseMissing('products', ['source' => Source::Amazon->value]);
    }

    #[Test]
    public function amazon_is_still_forbidden_from_the_catalogue(): void
    {
        // The gate itself, asserted directly. Flipping this one boolean would
        // silently re-enable Amazon storage at eight call sites, each of which
        // encodes its own compliance reasoning.
        $this->assertFalse(Source::Amazon->allowsCatalogueStorage());
        $this->assertTrue(Source::Bol->allowsCatalogueStorage());
    }

    #[Test]
    public function no_price_can_be_stored_by_any_route(): void
    {
        // Sent anyway, as a hostile or merely outdated client would. It is not
        // in the validated set, so it is dropped before the service sees it —
        // and there is nowhere for it to land if it were not.
        $this->importPage([$this->product(['price' => 4999, 'priceCents' => 4999])])->assertOk();

        $columns = Schema::getColumnListing('amazon_products');

        $this->assertSame(
            [],
            array_values(array_filter($columns, fn (string $c) => str_contains($c, 'price'))),
            'amazon_products grew a price column',
        );
    }

    // ── The barcode is the point ──────────────────────────────────────────

    #[Test]
    public function a_barcode_bridges_an_asin_to_a_product_we_already_sell(): void
    {
        /*
         * `amazon_products.identity_key` has been described as "the bridge"
         * since the table was created and nothing could ever fill it, because
         * Amazon publishes no barcodes through any interface this site had.
         * A product page prints one. This is what that buys.
         */
        $group = $this->seedCatalogueProduct('4905524930184');

        $response = $this->importPage([$this->product([
            'asin' => 'B00CHVA0YU',
            'title' => 'Sony MDR-ZX110',
            'ean' => '4905524930184',
        ])]);

        $response->assertOk()->assertJsonPath('results.0.matchedGroupId', $group->id);

        $product = AmazonProduct::query()->sole();

        $this->assertSame('4905524930184', $product->ean);
        $this->assertSame($group->identity_key, $product->identity_key);
    }

    #[Test]
    public function a_twelve_digit_upc_is_the_same_number_as_a_thirteen_digit_ean(): void
    {
        // Amazon prints both. Treating them as different numbers would file the
        // same physical product under two identities and break the bridge for
        // every American-market product, which is most of the ones with a
        // barcode at all.
        $this->importPage([$this->product(['ean' => '860001650310'])])->assertOk();

        $this->assertSame('0860001650310', AmazonProduct::query()->sole()->ean);
    }

    #[Test]
    public function a_barcode_that_is_not_one_is_ignored_rather_than_stored(): void
    {
        $this->importPage([$this->product(['ean' => 'n/a'])])->assertOk();

        $this->assertNull(AmazonProduct::query()->sole()->ean);
    }

    // ── Re-importing ──────────────────────────────────────────────────────

    #[Test]
    public function importing_the_shelf_after_the_product_does_not_erase_the_barcode(): void
    {
        /*
         * The realistic sequence, and the one that would quietly destroy the
         * most valuable field: open a product page, import it with its barcode
         * and description, then go back to the search results and import the
         * whole shelf. A results page carries a title and an image and nothing
         * else, so a blind overwrite would blank both.
         */
        $this->importPage([$this->product(['ean' => '4905524930184'])])->assertOk();

        $this->importPage([[
            'asin' => 'B0BTJD6LCL',
            'title' => 'Sony WH-CH520 Draadloze Bluetooth-hoofdtelefoon',
            'imageUrl' => 'https://m.media-amazon.com/images/I/71e6EQRCsL._AC_UL320_.jpg',
        ]])->assertOk()->assertJsonPath('results.0.status', 'updated');

        $product = AmazonProduct::query()->sole();

        $this->assertSame('4905524930184', $product->ean);
        $this->assertSame('On-ear-koptelefoons', $product->category);
        $this->assertStringContainsString('Digital Sound', (string) $product->description);
        // The field the second page DID carry is the one that moved.
        $this->assertStringContainsString('_AC_UL320_', (string) $product->image_url);
    }

    #[Test]
    public function seeing_a_product_on_a_second_storefront_adds_a_locale_rather_than_replacing_one(): void
    {
        $this->importPage([$this->product()], 'amazon.nl')->assertOk();
        $this->importPage([$this->product()], 'amazon.de')->assertOk();

        $this->assertSame(['amazon.nl', 'amazon.de'], AmazonProduct::query()->sole()->seen_in_locales);
        $this->assertDatabaseCount('amazon_products', 1);
    }

    // ── What is refused ───────────────────────────────────────────────────

    #[Test]
    public function a_product_with_no_title_is_reported_rather_than_stored(): void
    {
        // A title is the input to the giftability rules this table exists to
        // record, and with no API to ask there is no way to fill it in later.
        $this->importPage([['asin' => 'B0BTJD6LCL']])
            ->assertOk()
            ->assertJsonPath('imported', 0)
            ->assertJsonPath('results.0.status', 'skipped')
            ->assertJsonPath('results.0.reason', 'no title on the page');

        $this->assertDatabaseCount('amazon_products', 0);
    }

    #[Test]
    public function an_image_url_that_is_not_https_is_dropped(): void
    {
        // The same rule an affiliate URL gets, for the same reason: this string
        // came off somebody else's page, and escaping at render does not stop a
        // javascript: URL being exactly what it says it is.
        $this->importPage([$this->product(['imageUrl' => 'javascript:alert(1)'])])->assertOk();

        $this->assertNull(AmazonProduct::query()->sole()->image_url);
    }

    #[Test]
    public function a_storefront_giftcoves_does_not_cover_is_refused(): void
    {
        $this->importPage([$this->product()], 'amazon.com')
            ->assertStatus(422)
            ->assertJsonValidationErrors('locale');
    }

    #[Test]
    public function something_that_is_not_an_asin_is_refused(): void
    {
        $this->importPage([['asin' => '../etc/passwd', 'title' => 'x']])
            ->assertStatus(422)
            ->assertJsonValidationErrors('products.0.asin');
    }

    #[Test]
    public function a_read_only_key_cannot_import(): void
    {
        $readOnly = ApiToken::issue('reader', [ApiToken::READ])['token'];

        $this->withToken($readOnly)->postJson('/api/editorial/import/amazon', [
            'locale' => 'amazon.nl',
            'products' => [$this->product()],
        ])->assertForbidden();
    }

    #[Test]
    public function it_requires_a_token(): void
    {
        $this->postJson('/api/editorial/import/amazon', [
            'locale' => 'amazon.nl',
            'products' => [$this->product()],
        ])->assertUnauthorized();
    }

    /** A real catalogue product, from a source that is allowed in it. */
    private function seedCatalogueProduct(string $ean): ProductGroup
    {
        Merchant::create([
            'source' => Source::Awin->value,
            'external_id' => 'shop',
            'name' => 'Shop',
        ]);

        app(OfferUpserter::class)->upsert([
            new Offer(
                source: Source::Awin,
                externalId: 'shop-1',
                market: Market::NlNl,
                title: 'Sony MDR-ZX110 koptelefoon',
                affiliateUrl: 'https://www.awin1.com/pclick.php?p=1',
                price: 1999,
                merchantName: 'Shop',
                merchantExternalId: 'shop',
                merchantDeepLink: 'https://shop.example/p/1',
                imageUrl: 'https://shop.example/1.jpg',
                ean: $ean,
                availability: Availability::InStock,
            ),
        ]);

        app(ProductGrouper::class)->run(Market::NlNl);

        return ProductGroup::query()->sole();
    }
}
