<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\PageReading\SlugTitle;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/** A product name read from the link, for when the shop refuses to show the page. */
class SlugTitleTest extends TestCase
{
    #[Test]
    #[DataProvider('links')]
    public function a_name_is_read_from_the_link(string $url, ?string $title): void
    {
        $this->assertSame($title, SlugTitle::fromUrl($url));
    }

    /** @return array<string, array{0: string, 1: string|null}> */
    public static function links(): array
    {
        return [
            'de Bijenkorf, codes dropped' => ['https://www.debijenkorf.be/d/bialetti-moka-express-percolator-6-kops-8834090013-883409001300000', 'Bialetti moka express percolator 6 kops'],
            'a model number stays' => ['https://www.bol.com/be/nl/p/sony-wh-1000xm5/9300000123/', 'Sony wh 1000xm5'],
            'a .html page' => ['https://shop.example/nl/linnen-schort-naturel.html', 'Linnen schort naturel'],
            'only an id' => ['https://shop.example/p/12345', null],
            'one word is not a name' => ['https://shop.example/products/mug', null],
            'no path' => ['https://shop.example/', null],
        ];
    }
}
