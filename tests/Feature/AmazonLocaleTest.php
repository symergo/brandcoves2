<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\AmazonLocale;
use App\Enums\Market;
use App\Models\AmazonProduct;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Amazon across locales.
 *
 * An ASIN is the same physical product on every storefront, so the verdict is
 * computed once and the price is fetched per locale. The rule that keeps that
 * honest: a foreign locale is a labelled extra and never a row in the
 * comparison.
 */
class AmazonLocaleTest extends TestCase
{
    use RefreshDatabase;

    private function product(array $attributes = []): AmazonProduct
    {
        return AmazonProduct::create([
            'asin' => 'B08N5WRWNW',
            'classified_title' => 'Echo Dot',
            'classified_locale' => AmazonLocale::Nl->value,
            'giftable' => true,
            'giftable_reason' => 'ok',
            'surprise_score' => 55,
            'seen_in_locales' => [AmazonLocale::Nl->value, AmazonLocale::De->value],
            ...$attributes,
        ]);
    }

    #[Test]
    public function one_asin_carries_one_verdict_for_every_market(): void
    {
        $product = $this->product();

        /*
         * There is no market column, on purpose.
         *
         * Giftability is a property of the product, not the storefront.
         * Classifying per locale would spend five times the compute to produce
         * five answers that should be identical — and would not be, because the
         * classifier reads the title and the title is translated.
         */
        $this->assertArrayNotHasKey('market', $product->getAttributes());
        $this->assertTrue($product->giftable);
    }

    #[Test]
    public function the_decision_is_stored_but_never_the_catalogue(): void
    {
        $columns = array_keys($this->product()->getAttributes());

        // Amazon may not be mirrored. Price, availability, image and
        // description are re-fetched live and a failed fetch hides the item.
        foreach (['price', 'availability', 'image_url', 'description'] as $forbidden) {
            $this->assertNotContains($forbidden, $columns);
        }
    }

    #[Test]
    public function every_market_gets_a_sensible_primary_locale(): void
    {
        $this->assertSame(AmazonLocale::Nl, AmazonLocale::primaryFor(Market::NlNl));
        $this->assertSame(AmazonLocale::Es, AmazonLocale::primaryFor(Market::Es));

        // Belgium is the awkward one: amazon.com.be exists but is thin, so a
        // Belgian shopper is better served by the language-matched neighbour.
        $this->assertSame(AmazonLocale::Nl, AmazonLocale::primaryFor(Market::BeNl));
        $this->assertSame(AmazonLocale::Fr, AmazonLocale::primaryFor(Market::BeFr));
    }

    #[Test]
    public function every_locale_is_selectable_from_every_market(): void
    {
        $locales = AmazonLocale::selectableFor(Market::BeFr);

        // All of them. Someone who reads French and lives in Belgium may still
        // want the German price, and hiding it is us guessing on their behalf
        // about something they can see for themselves.
        $this->assertCount(count(AmazonLocale::cases()), $locales);
        $this->assertSame(AmazonLocale::Fr, $locales[0]);
    }

    #[Test]
    public function only_the_primary_locale_may_enter_the_comparison(): void
    {
        /*
         * THE RULE THIS FILE EXISTS FOR.
         *
         * Showing "also on amazon.de for €40" is useful. Letting that price win
         * "cheapest" is not: it carries foreign tax and cross-border shipping,
         * and it would beat a local offer the shopper can actually act on. That
         * is precisely the failure market-scoped identity exists to prevent.
         */
        $this->assertTrue(AmazonLocale::Nl->isComparableIn(Market::NlNl));
        $this->assertFalse(AmazonLocale::De->isComparableIn(Market::NlNl));
        $this->assertFalse(AmazonLocale::Uk->isComparableIn(Market::BeNl));
    }

    #[Test]
    public function known_stocked_locales_are_offered_first(): void
    {
        $locales = $this->product()->localesFor(Market::BeFr);

        // Primary first regardless, then the ones we have actually seen it in,
        // so the useful tabs come before the speculative ones.
        $this->assertSame(AmazonLocale::Fr, $locales[0]);
        $this->assertContains(AmazonLocale::De, array_slice($locales, 1, 2));
    }

    #[Test]
    public function an_asin_must_look_like_an_asin(): void
    {
        $this->expectException(QueryException::class);

        // Ten uppercase alphanumerics. A malformed one is a parse error
        // upstream, and storing it would produce a link to nowhere.
        $this->product(['asin' => 'not-an-asin']);
    }
}
