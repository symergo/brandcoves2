<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Cove\VariantKey;
use PHPUnit\Framework\TestCase;

/**
 * The titles are the 2 Oct 2026 Dailies that showed one product several times.
 */
class VariantKeyTest extends TestCase
{
    public function test_sizes_of_one_product_share_a_key(): void
    {
        $this->assertSame(
            VariantKey::of('Alwero Sloffen Basic Mono Naturel 41/42'),
            VariantKey::of('Alwero Sloffen Basic Mono Naturel 37/38'),
        );
    }

    public function test_size_words_and_measures_are_not_the_product(): void
    {
        $this->assertSame(VariantKey::of('Badjas Wafel Maat 42'), VariantKey::of('Badjas Wafel Maat 46'));
        $this->assertSame(VariantKey::of('Olijfolie extra vierge 500 ml'), VariantKey::of('Olijfolie extra vierge 1 l'));
    }

    public function test_colours_of_one_product_share_a_key(): void
    {
        $this->assertSame(
            VariantKey::of('Hello Kitty Sloffen Wit – Dames & Meisjes – Warm & Zacht'),
            VariantKey::of('Hello Kitty Sloffen Roze – Dames & Meisjes – Warm & Zacht'),
        );
    }

    public function test_repeated_titles_share_a_key(): void
    {
        $this->assertSame(
            VariantKey::of('Sonic the Hedgehog Pantoffels Sloffen'),
            VariantKey::of('Sonic the Hedgehog Pantoffels Sloffen'),
        );
    }

    public function test_different_products_keep_different_keys(): void
    {
        $this->assertNotSame(
            VariantKey::of('Sonic the Hedgehog Pantoffels Sloffen'),
            VariantKey::of('Sonic the Hedgehog Rugzak'),
        );
        $this->assertNotSame(
            VariantKey::of('Alwero Sloffen Basic Mono Naturel 41/42'),
            VariantKey::of('Hot Potatoes Harrietta Sloffen Dames - Burgundy'),
        );
        // A number that names the product is not a size.
        $this->assertNotSame(VariantKey::of('Apple iPhone 15 hoesje'), VariantKey::of('Apple iPhone 16 hoesje'));
        $this->assertNotSame(VariantKey::of('LEGO Technic 42115'), VariantKey::of('LEGO Technic 42143'));
        $this->assertNotSame(
            VariantKey::of('Russell Hobbs Groove Waterkoker Zwart - 26380-70'),
            VariantKey::of('Russell Hobbs 24191-70 Compact Home Glass - Kleine Waterkoker'),
        );
    }
}
