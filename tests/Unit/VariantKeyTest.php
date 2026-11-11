<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Cove\VariantKey;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The titles are the 2 Oct 2026 Dailies that showed one product several times.
 */
class VariantKeyTest extends TestCase
{
    /** @return iterable<string, array{string, string}> */
    public static function variantsOfOneProduct(): iterable
    {
        yield 'sizes' => ['Alwero Sloffen Basic Mono Naturel 41/42', 'Alwero Sloffen Basic Mono Naturel 37/38'];
        // Size words and measures are not the product.
        yield 'size word' => ['Badjas Wafel Maat 42', 'Badjas Wafel Maat 46'];
        yield 'measure' => ['Olijfolie extra vierge 500 ml', 'Olijfolie extra vierge 1 l'];
        yield 'colours' => [
            'Hello Kitty Sloffen Wit – Dames & Meisjes – Warm & Zacht',
            'Hello Kitty Sloffen Roze – Dames & Meisjes – Warm & Zacht',
        ];
    }

    #[DataProvider('variantsOfOneProduct')]
    public function test_variants_of_one_product_share_a_key(string $a, string $b): void
    {
        $this->assertSame(VariantKey::of($a), VariantKey::of($b));
    }

    /** @return iterable<string, array{string, string}> */
    public static function differentProducts(): iterable
    {
        yield 'same brand, other product' => ['Sonic the Hedgehog Pantoffels Sloffen', 'Sonic the Hedgehog Rugzak'];
        yield 'same kind, other brand' => ['Alwero Sloffen Basic Mono Naturel 41/42', 'Hot Potatoes Harrietta Sloffen Dames - Burgundy'];
        // A number that names the product is not a size.
        yield 'model number in the name' => ['Apple iPhone 15 hoesje', 'Apple iPhone 16 hoesje'];
        yield 'set number' => ['LEGO Technic 42115', 'LEGO Technic 42143'];
        yield 'part number' => [
            'Russell Hobbs Groove Waterkoker Zwart - 26380-70',
            'Russell Hobbs 24191-70 Compact Home Glass - Kleine Waterkoker',
        ];
    }

    #[DataProvider('differentProducts')]
    public function test_different_products_keep_different_keys(string $a, string $b): void
    {
        $this->assertNotSame(VariantKey::of($a), VariantKey::of($b));
    }
}
