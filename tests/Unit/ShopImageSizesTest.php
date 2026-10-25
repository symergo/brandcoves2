<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Images\ShopImageSizes;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The shop's own picture per width, which replaced the image proxy on
 * 2026-10-25. See App\Services\Images\ShopImageSizes.
 */
class ShopImageSizesTest extends TestCase
{
    #[Test]
    public function coolblue_bynder_gets_a_box_of_each_width_smaller_and_larger(): void
    {
        $sizes = ShopImageSizes::for('https://coolblue.bynder.com/transform/abc/def?io=transform:fit,height:800,width:800&format=png');

        $this->assertSame('https://coolblue.bynder.com/transform/abc/def?io=transform:fit,height:160,width:160&format=png', $sizes[160]);
        $this->assertSame('https://coolblue.bynder.com/transform/abc/def?io=transform:fit,height:960,width:960&format=png', $sizes[960]);
    }

    #[Test]
    public function ebay_gets_the_smallest_fixed_rendition_that_covers_each_width(): void
    {
        $sizes = ShopImageSizes::for('https://i.ebayimg.com/images/g/AbC/s-l500.jpg');

        $this->assertSame('https://i.ebayimg.com/images/g/AbC/s-l225.jpg', $sizes[160]);
        $this->assertSame('https://i.ebayimg.com/images/g/AbC/s-l400.jpg', $sizes[320]);
        $this->assertSame('https://i.ebayimg.com/images/g/AbC/s-l960.jpg', $sizes[960]);
    }

    #[Test]
    public function awin_productserve_gets_each_width_up_to_its_ceiling(): void
    {
        $sizes = ShopImageSizes::for('https://images2.productserve.com/?w=200&h=200&bg=white&url=ssl%3Aexample.test%2Fa.jpg');

        $this->assertSame('https://images2.productserve.com/?w=320&h=320&bg=white&url=ssl%3Aexample.test%2Fa.jpg', $sizes[320]);
        $this->assertStringContainsString('w=960&h=960', $sizes[960]);
    }

    #[Test]
    public function any_other_server_is_used_as_it_is(): void
    {
        // bol's size is fixed by the hash in the path; asking for another 404s.
        $this->assertNull(ShopImageSizes::for('https://media.s-bol.com/abc/def/250x200.jpg'));
        $this->assertNull(ShopImageSizes::for('https://shop.example/picture.jpg'));
        $this->assertNull(ShopImageSizes::for('http://coolblue.bynder.com/transform/a?io=transform:fit,height:800,width:800'));
        $this->assertNull(ShopImageSizes::for(null));
    }
}
