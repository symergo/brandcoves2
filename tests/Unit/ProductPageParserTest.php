<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\PageReading\PageProduct;
use App\Services\PageReading\ProductPageParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Reading a product out of a shop's HTML.
 *
 * The shapes here are the ones real storefronts emit: Shopify's single Product,
 * WooCommerce's `@graph`, an AggregateOffer, a ProductGroup with variants, and
 * a page with nothing but Open Graph.
 */
class ProductPageParserTest extends TestCase
{
    private const URL = 'https://shop.example/products/mug';

    #[Test]
    public function a_json_ld_product_gives_every_field(): void
    {
        $page = $this->parse($this->ld([
            '@context' => 'https://schema.org',
            '@type' => 'Product',
            'name' => 'Stoneware mug, sage',
            'brand' => ['@type' => 'Brand', 'name' => 'Potter & Co'],
            'description' => '<p>Hand-thrown.</p>',
            'image' => ['/cdn/mug.jpg', '/cdn/mug-2.jpg'],
            'gtin13' => '4006381333931',
            'mpn' => 'MUG-01',
            'offers' => ['@type' => 'Offer', 'price' => '24.50', 'priceCurrency' => 'EUR', 'availability' => 'https://schema.org/InStock'],
        ]));

        $this->assertSame('Stoneware mug, sage', $page->title);
        $this->assertSame('Potter & Co', $page->brand);
        $this->assertSame('Hand-thrown.', $page->description, 'Tags stripped: a description is shown as text.');
        $this->assertSame('https://shop.example/cdn/mug.jpg', $page->imageUrl, 'Relative image made absolute against the page.');
        $this->assertSame(2450, $page->price);
        $this->assertSame('EUR', $page->currency);
        $this->assertSame('in_stock', $page->availability);
        $this->assertSame('4006381333931', $page->gtin);
        $this->assertSame('MUG-01', $page->mpn);
    }

    #[Test]
    public function a_product_inside_a_graph_is_found_and_preferred_over_related_ones(): void
    {
        // WooCommerce and Yoast wrap everything in @graph; the page's own
        // product is the one with an offer, the others are "related".
        $page = $this->parse($this->ld([
            '@context' => 'https://schema.org',
            '@graph' => [
                ['@type' => 'WebPage', 'name' => 'Shop'],
                ['@type' => 'Product', 'name' => 'Related thing'],
                ['@type' => 'Product', 'name' => 'The real mug', 'offers' => [['@type' => 'Offer', 'price' => 12, 'priceCurrency' => 'EUR']]],
            ],
        ]));

        $this->assertSame('The real mug', $page->title);
        $this->assertSame(1200, $page->price);
    }

    #[Test]
    public function an_aggregate_offer_gives_its_low_price(): void
    {
        $page = $this->parse($this->ld([
            '@type' => 'Product',
            'name' => 'Board game',
            'offers' => ['@type' => 'AggregateOffer', 'lowPrice' => 19.99, 'highPrice' => 29.99, 'priceCurrency' => 'EUR'],
        ]));

        $this->assertSame(1999, $page->price);
    }

    #[Test]
    public function a_variant_group_takes_a_price_but_not_a_single_sizes_barcode(): void
    {
        $page = $this->parse($this->ld([
            '@type' => 'ProductGroup',
            'name' => 'Wool jumper',
            'hasVariant' => [
                ['@type' => 'Product', 'name' => 'Wool jumper S', 'gtin13' => '4006381333931', 'offers' => ['@type' => 'Offer', 'price' => '89.00', 'priceCurrency' => 'EUR']],
            ],
        ]));

        $this->assertSame('Wool jumper', $page->title);
        $this->assertSame(8900, $page->price);
        $this->assertNull($page->gtin, 'The barcode of size S is not the barcode of the jumper.');
    }

    #[Test]
    public function open_graph_fills_what_json_ld_does_not_have(): void
    {
        $html = '<html><head>'
            .'<meta property="og:title" content="Linen apron">'
            .'<meta property="og:image" content="https://cdn.shop.example/apron.jpg">'
            .'<meta property="product:price:amount" content="35,00">'
            .'<meta property="product:price:currency" content="eur">'
            .'</head></html>';

        $page = (new ProductPageParser)->parse($html, self::URL);

        $this->assertSame('Linen apron', $page->title);
        $this->assertSame('https://cdn.shop.example/apron.jpg', $page->imageUrl);
        $this->assertSame(3500, $page->price);
        $this->assertSame('EUR', $page->currency);
    }

    #[Test]
    public function a_page_with_only_a_title_gives_a_title_and_nothing_else(): void
    {
        // No guessing a price out of prose: that is how a shipping cost ends
        // up shown as the price of a present.
        $page = (new ProductPageParser)->parse('<html><head><title> Café  crème set </title></head><body>Only €9.99!</body></html>', self::URL);

        $this->assertSame('Café crème set', $page->title, 'UTF-8 kept, whitespace collapsed.');
        $this->assertNull($page->price);
        $this->assertNull($page->imageUrl);
    }

    #[Test]
    public function an_image_that_is_not_https_is_not_kept(): void
    {
        $page = $this->parse($this->ld(['@type' => 'Product', 'name' => 'Mug', 'image' => 'http://insecure.example/mug.jpg']));

        $this->assertNull($page->imageUrl);
    }

    #[Test]
    public function nothing_at_all_is_null(): void
    {
        $this->assertNull((new ProductPageParser)->parse('', self::URL));
        $this->assertNull((new ProductPageParser)->parse('<html><body>no title</body></html>', self::URL));
    }

    #[Test]
    #[DataProvider('prices')]
    public function prices_are_read_as_cents_without_float_arithmetic(mixed $raw, ?int $cents): void
    {
        $this->assertSame($cents, ProductPageParser::cents($raw));
    }

    /** @return array<string, array{0: mixed, 1: int|null}> */
    public static function prices(): array
    {
        return [
            'dot decimal' => ['12.50', 1250],
            'comma decimal' => ['12,50', 1250],
            'thousands dot, comma decimal' => ['1.299,00', 129900],
            'thousands comma, dot decimal' => ['1,299.00', 129900],
            'currency sign' => ['€ 12', 1200],
            'one decimal' => ['12.5', 1250],
            'json float' => [12.5, 1250],
            'json int' => [12, 1200],
            'a product code, not a price' => ['123456789', null],
            'text' => ['free', null],
            'negative' => [-5, null],
        ];
    }

    private function parse(string $html): ?PageProduct
    {
        return (new ProductPageParser)->parse($html, self::URL);
    }

    /** @param  array<string, mixed>  $data */
    private function ld(array $data): string
    {
        return '<html><head><title>Fallback</title><script type="application/ld+json">'
            .json_encode($data, JSON_UNESCAPED_SLASHES)
            .'</script></head><body></body></html>';
    }
}
