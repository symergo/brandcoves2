<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Search\AmazonLink;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Taking a pasted Amazon URL apart.
 *
 * Pure string work, so a plain unit test. The cases are real link shapes: the
 * long one with the title in it, the bare mobile one, the ancient
 * `/exec/obidos/` form that still resolves, and the hostile ones.
 */
class AmazonLinkTest extends TestCase
{
    /** @return array<string, array{0: string, 1: string}> */
    public static function asinShapes(): array
    {
        return [
            'desktop with title slug' => [
                'https://www.amazon.nl/Sony-WH-1000XM5-Draadloze-Koptelefoon-Zwart/dp/B09XS7JWHH/ref=sr_1_3?keywords=koptelefoon',
                'B09XS7JWHH',
            ],
            'bare dp' => ['https://www.amazon.nl/dp/B09XS7JWHH', 'B09XS7JWHH'],
            'gp product' => ['https://www.amazon.de/gp/product/B07FZ8S74R?th=1', 'B07FZ8S74R'],
            'mobile gp aw d' => ['https://www.amazon.co.uk/gp/aw/d/B01N5IB20Q', 'B01N5IB20Q'],
            'offer listing' => ['https://www.amazon.fr/gp/offer-listing/B00X4WHP55', 'B00X4WHP55'],
            'obidos' => ['https://www.amazon.com/exec/obidos/ASIN/0140449132/ref=nosim', '0140449132'],
            'asin query parameter' => ['https://www.amazon.es/product-reviews?asin=B0863TXGM3', 'B0863TXGM3'],
            'lowercase asin is upcased' => ['https://www.amazon.nl/dp/b09xs7jwhh', 'B09XS7JWHH'],
            'no scheme' => ['amazon.nl/dp/B09XS7JWHH', 'B09XS7JWHH'],
            'country subdomain' => ['https://smile.amazon.co.uk/dp/B01N5IB20Q', 'B01N5IB20Q'],
        ];
    }

    #[Test]
    #[DataProvider('asinShapes')]
    public function it_finds_the_asin(string $url, string $expected): void
    {
        $this->assertSame($expected, AmazonLink::parse($url)?->asin);
    }

    #[Test]
    public function the_title_slug_becomes_the_search_terms(): void
    {
        // The whole point. The catalogue holds no Amazon rows, so the ASIN
        // matches nothing today; the product's own name is what finds it at the
        // shops we do carry.
        $link = AmazonLink::parse('https://www.amazon.nl/Sony-WH-1000XM5-Draadloze-Koptelefoon-Zwart/dp/B09XS7JWHH/ref=sr_1_3');

        $this->assertSame('Sony WH 1000XM5 Draadloze Koptelefoon Zwart', $link?->terms);
        $this->assertTrue($link?->isUsable());
    }

    #[Test]
    public function a_link_with_no_title_falls_back_to_its_keywords(): void
    {
        $link = AmazonLink::parse('https://www.amazon.nl/s?k=draadloze+koptelefoon&keywords=draadloze+koptelefoon');

        $this->assertSame('draadloze koptelefoon', $link?->terms);
    }

    #[Test]
    public function a_bare_dp_link_yields_an_asin_and_nothing_to_search_for(): void
    {
        // Honest emptiness. There is no title in the URL and we will not fetch
        // one, so the caller has to say it cannot identify the product rather
        // than run a query built from routing tokens.
        $link = AmazonLink::parse('https://www.amazon.nl/dp/B09XS7JWHH');

        $this->assertSame('B09XS7JWHH', $link?->asin);
        $this->assertSame('', $link?->terms);
        $this->assertFalse($link?->isUsable());
    }

    #[Test]
    public function a_single_word_segment_is_not_treated_as_a_title(): void
    {
        // One word off an Amazon URL is nearly always a leftover routing token,
        // and searching for it returns a page of unrelated products that looks
        // like we understood the link.
        $this->assertSame('', AmazonLink::parse('https://www.amazon.nl/stores/dp/B09XS7JWHH')?->terms);
    }

    #[Test]
    public function a_shortlink_is_recognised_and_not_followed(): void
    {
        /*
         * Never fetched. A visitor-supplied URL that the server requests is
         * SSRF with a search box in front of it, and it would put Amazon's
         * latency inside our request handler.
         */
        $link = AmazonLink::parse('https://amzn.to/3xYzAbC');

        $this->assertTrue($link?->shortlink);
        $this->assertNull($link?->asin);
        $this->assertFalse($link?->isUsable());

        $this->assertTrue(AmazonLink::parse('https://amzn.eu/d/hK2mQ1p')?->shortlink);
    }

    /** @return array<string, array{0: string}> */
    public static function notAmazon(): array
    {
        return [
            'ordinary search term' => ['draadloze koptelefoon'],
            'a term that mentions amazon' => ['amazon echo dot'],
            'another shop' => ['https://www.bol.com/nl/nl/p/sony-wh-1000xm5/9300000096894/'],
            'amazon in the path of another host' => ['https://evil.test/www.amazon.nl/dp/B09XS7JWHH'],
            'amazon as a subdomain prefix of another host' => ['https://amazon.nl.evil.test/dp/B09XS7JWHH'],
            'lookalike domain' => ['https://amazon-nl.test/dp/B09XS7JWHH'],
            'empty' => [''],
        ];
    }

    #[Test]
    #[DataProvider('notAmazon')]
    public function it_leaves_everything_else_alone(string $input): void
    {
        // Null means "ordinary search term", so a false positive here would
        // hijack a real query. The host is matched on the parsed host and
        // anchored at both ends.
        $this->assertNull(AmazonLink::parse($input));
    }

    #[Test]
    public function an_absurdly_long_paste_is_refused(): void
    {
        $this->assertNull(AmazonLink::parse('https://www.amazon.nl/dp/B09XS7JWHH?x='.str_repeat('a', 4000)));
    }
}
