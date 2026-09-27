<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\Market;
use App\Models\ProductGroup;
use App\Services\Seo\Alternates;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A product's hreflang alternates are its twins in the other markets.
 *
 * Both lookups name every market since 2026-09-27, so the `(market,
 * identity_key)` index can answer them. That condition must filter nothing
 * out: every twin in every market is still found.
 */
class ProductAlternatesTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, ProductGroup> market value => group */
    private function twins(): array
    {
        $twins = [];

        foreach (Market::cases() as $market) {
            $twins[$market->value] = ProductGroup::factory()->forMarket($market)->create([
                'identity_key' => '4006381333931',
            ]);
        }

        // A different product in the same markets, which must not join in.
        ProductGroup::factory()->forMarket(Market::BeFr)->create(['identity_key' => '4006381333948']);

        return $twins;
    }

    /** @param array<string, ProductGroup> $twins */
    private function expected(array $twins): array
    {
        $expected = [];

        foreach ($twins as $group) {
            $expected[$group->market->hrefLang()] = url("/{$group->market->value}/p/{$group->id}/{$group->slug}");
        }

        ksort($expected);

        return $expected;
    }

    #[Test]
    public function a_product_page_lists_its_twin_in_every_market(): void
    {
        $twins = $this->twins();
        $page = $twins[Market::BeNl->value];

        $alternates = app(Alternates::class)->for("/be-nl/p/{$page->id}/{$page->slug}", Market::BeNl);
        unset($alternates['x-default']);
        ksort($alternates);

        $this->assertSame($this->expected($twins), $alternates);
    }

    #[Test]
    public function a_sitemap_batch_lists_every_twin_too(): void
    {
        $twins = $this->twins();
        $page = $twins[Market::En->value];

        $alternates = app(Alternates::class)->forProducts([$page->id => '4006381333931'])[$page->id];
        ksort($alternates);

        $this->assertSame($this->expected($twins), $alternates);
    }
}
