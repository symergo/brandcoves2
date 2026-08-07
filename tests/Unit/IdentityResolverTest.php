<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Enums\IdentityKind;
use App\Services\Identity\IdentityResolver;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Extends the framework TestCase rather than PHPUnit's, because the resolver
 * reads its guards from config — thresholds belong in config, not literals.
 */
class IdentityResolverTest extends TestCase
{
    #[Test]
    public function a_valid_ean_wins_over_everything(): void
    {
        $identity = IdentityResolver::resolve('4006381333931', 'Sony', 'Headphones');

        $this->assertNotNull($identity);
        $this->assertSame('4006381333931', $identity->key);
        $this->assertSame(IdentityKind::Ean, $identity->kind);
        $this->assertTrue($identity->isAuthoritative());
    }

    #[Test]
    public function it_falls_back_to_brand_and_title_without_an_ean(): void
    {
        $identity = IdentityResolver::resolve(null, 'Sony', 'WH-1000XM5 Wireless Headphones');

        $this->assertNotNull($identity);
        $this->assertSame(IdentityKind::Title, $identity->kind);
        $this->assertStringStartsWith('sony|', $identity->key);
    }

    #[Test]
    public function a_placeholder_ean_falls_through_to_the_title_path(): void
    {
        // "0" is the single most common feed placeholder. If it were trusted,
        // every product carrying it would merge into one group.
        $identity = IdentityResolver::resolve('0', 'Sony', 'WH-1000XM5 Wireless Headphones');

        $this->assertNotNull($identity);
        $this->assertSame(IdentityKind::Title, $identity->kind);
    }

    #[Test]
    public function two_shops_listing_the_same_product_agree(): void
    {
        $a = IdentityResolver::resolve(null, 'Sony', 'Sony WH-1000XM5 Koptelefoon (2024)');
        $b = IdentityResolver::resolve(null, 'SONY', 'Sony WH-1000XM5 Koptelefoon - Gratis verzending');

        $this->assertNotNull($a);
        $this->assertNotNull($b);
        $this->assertSame($a->key, $b->key, 'merchant noise must not split one product into two');
    }

    #[Test]
    public function different_models_do_not_merge(): void
    {
        // The most dangerous near-miss: one character apart.
        $xm5 = IdentityResolver::resolve(null, 'Sony', 'Sony WH-1000XM5 Koptelefoon');
        $xm4 = IdentityResolver::resolve(null, 'Sony', 'Sony WH-1000XM4 Koptelefoon');

        $this->assertNotSame($xm5?->key, $xm4?->key);
    }

    #[Test]
    public function accents_do_not_split_a_product(): void
    {
        $a = IdentityResolver::resolve(null, 'DeLonghi', 'Cafetière à piston 1 litre');
        $b = IdentityResolver::resolve(null, 'DeLonghi', 'Cafetiere a piston 1 litre');

        $this->assertNotNull($a);
        $this->assertSame($a->key, $b?->key);
    }

    #[Test]
    public function rows_with_no_brand_are_left_ungrouped(): void
    {
        // "Wireless Headphones" from two shops is very unlikely to be the same
        // object, and a wrong merge is worse than no merge.
        $this->assertNull(IdentityResolver::resolve(null, null, 'Wireless Headphones'));
        $this->assertNull(IdentityResolver::resolve(null, '', 'Wireless Headphones'));
    }

    #[Test]
    public function unbranded_markers_do_not_count_as_a_brand(): void
    {
        foreach (['Merkloos', 'unbranded', 'Generic', 'N/A', 'Geen', 'Overig'] as $marker) {
            $this->assertNull(
                IdentityResolver::resolve(null, $marker, 'Wireless Headphones Bluetooth'),
                "\"{$marker}\" is not a brand",
            );
        }
    }

    #[Test]
    public function short_titles_are_left_ungrouped(): void
    {
        // "Cable", "Case", "Hoes" collide trivially across a catalogue.
        $this->assertNull(IdentityResolver::resolve(null, 'Sony', 'Kabel'));
        $this->assertNull(IdentityResolver::resolve(null, 'Apple', 'Case'));
    }

    #[Test]
    public function a_title_that_is_only_the_brand_carries_no_information(): void
    {
        $this->assertNull(IdentityResolver::resolve(null, 'Bang & Olufsen', 'Bang & Olufsen'));
    }

    #[Test]
    public function the_fallback_can_be_switched_off(): void
    {
        config(['brandcoves.identity.allow_title_fallback' => false]);

        $this->assertNull(IdentityResolver::resolve(null, 'Sony', 'Sony WH-1000XM5 Koptelefoon'));
        // The EAN path is unaffected.
        $this->assertNotNull(IdentityResolver::resolve('4006381333931', 'Sony', 'Sony WH-1000XM5'));
    }

    #[Test]
    public function title_normalisation_strips_merchant_noise(): void
    {
        $this->assertSame(
            'sony wh 1000xm5 koptelefoon',
            IdentityResolver::normaliseTitle('Sony WH-1000XM5 Koptelefoon (2024 model)'),
        );
    }
}
