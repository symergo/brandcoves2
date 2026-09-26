<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\PageReading\IframelyReader;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/** How Iframely's answer becomes what a page says. See IframelyReader. */
class IframelyReaderTest extends TestCase
{
    #[Test]
    public function a_product_answer_gives_title_brand_price_stock_and_the_first_https_picture(): void
    {
        $page = IframelyReader::parse([
            'meta' => [
                'title' => 'Bialetti Moka Express &amp; co',
                'brand' => 'Bialetti',
                'price' => 33.95,
                'currency' => 'eur',
                'availability' => 'https://schema.org/InStock',
            ],
            'links' => ['thumbnail' => [
                ['href' => 'http://insecure.example/a.jpg'],
                ['href' => 'https://cdn.example/b.jpg'],
            ]],
        ]);

        $this->assertNotNull($page);
        $this->assertSame('Bialetti Moka Express & co', $page->title);
        $this->assertSame('Bialetti', $page->brand);
        $this->assertSame(3395, $page->price);
        $this->assertSame('EUR', $page->currency);
        $this->assertSame('in_stock', $page->availability);
        $this->assertSame('https://cdn.example/b.jpg', $page->imageUrl);
    }

    #[Test]
    public function no_title_means_nothing_was_learned(): void
    {
        $this->assertNull(IframelyReader::parse(['meta' => ['price' => 10]]));
        $this->assertNull(IframelyReader::parse(['error' => 'Not found']));
    }

    #[Test]
    public function a_strange_currency_or_price_is_left_out_rather_than_guessed(): void
    {
        $page = IframelyReader::parse(['meta' => ['title' => 'Mug', 'price' => -3, 'currency' => 'euros']]);

        $this->assertNotNull($page);
        $this->assertNull($page->price);
        $this->assertNull($page->currency);
        $this->assertNull($page->imageUrl);
    }
}
