<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\IdentityKind;
use App\Enums\Market;
use App\Models\BrandStat;
use App\Models\ProductGroup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The hand-off to Amazon, on the two pages that carry it.
 *
 * The interesting assertions here are all about *absence*. A link that appears
 * where it should not is a visible bug somebody reports; a link that is present
 * but untagged, or tagged for the wrong marketplace, renders identically to a
 * working one and is only discovered by an Associates report that never fills
 * up. So the tag and the storefront are asserted together, per market.
 */
class AmazonSearchCtaTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function the_search_sidebar_carries_the_term_and_the_market_tag(): void
    {
        $this->get('/nl-nl/search?q=koptelefoon')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('amazonSearch.host', 'www.amazon.nl')
                ->where('amazonSearch.url', 'https://www.amazon.nl/s?k=koptelefoon&tag=giftcoves-21')
            );

        $this->get('/be-nl/search?q=koptelefoon')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('amazonSearch.host', 'www.amazon.com.be')
                ->where('amazonSearch.url', 'https://www.amazon.com.be/s?k=koptelefoon&tag=giftcoves05-21')
            );
    }

    #[Test]
    public function a_market_without_a_tag_gets_no_link(): void
    {
        $this->get('/en/search?q=headphones')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('amazonSearch', null));
    }

    /**
     * The search page before anything is typed still offers the link — the
     * storefront itself, tagged, and labelled without a term to quote.
     */
    #[Test]
    public function a_search_with_no_term_still_offers_the_storefront(): void
    {
        $this->get('/nl-nl/search')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('amazonSearch.url', 'https://www.amazon.nl/?tag=giftcoves-21')
                ->where('amazonSearch.hasTerm', false)
            );
    }

    /**
     * A dead end is where this link is worth most: we found nothing, and the
     * shopper's question is still open.
     */
    #[Test]
    public function a_search_that_found_nothing_still_offers_the_link(): void
    {
        $this->get('/nl-nl/search?q=iets-wat-hier-niet-bestaat')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('results.total', 0)
                ->where('amazonSearch.url', 'https://www.amazon.nl/s?k=iets-wat-hier-niet-bestaat&tag=giftcoves-21')
            );
    }

    /**
     * The brand page hands across the brand plus whatever term chips are
     * narrowing it — not "Sony" when the visitor has clicked down to Sony
     * headphones.
     */
    #[Test]
    public function the_brand_page_searches_the_brand_and_its_narrowing_terms(): void
    {
        BrandStat::query()->create([
            'market' => Market::BeNl->value,
            'brand' => 'Aurex',
            'slug' => 'aurex',
            'aliases' => ['Aurex'],
            'product_count' => 5,
            'merchant_count' => 2,
            'computed_at' => now(),
        ]);

        $this->get('/be-nl/brand/aurex')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('amazonSearch.url', 'https://www.amazon.com.be/s?k=Aurex&tag=giftcoves05-21')
            );

        $this->get('/be-nl/brand/aurex?q=koptelefoon')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('amazonSearch.url', 'https://www.amazon.com.be/s?k=Aurex%20koptelefoon&tag=giftcoves05-21')
            );
    }

    #[Test]
    public function the_product_page_searches_the_barcode(): void
    {
        $group = ProductGroup::factory()->create([
            'market' => Market::BeNl,
            'identity_kind' => IdentityKind::Ean,
            'identity_key' => '8712345678901',
        ]);

        $this->get("/be-nl/p/{$group->id}/{$group->slug}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('amazonSearch.url', 'https://www.amazon.com.be/s?k=8712345678901&tag=giftcoves05-21')
            );
    }

    /**
     * A group identified by a folded title has no barcode, and a title search
     * on Amazon returns the accessories and the previous generation rather than
     * this product. No link is the truthful answer.
     */
    #[Test]
    public function a_product_without_a_barcode_gets_no_link(): void
    {
        $group = ProductGroup::factory()->create([
            'market' => Market::BeNl,
            'identity_kind' => IdentityKind::Title,
            'identity_key' => 'sony|wh 1000xm5 draadloze koptelefoon',
        ]);

        $this->get("/be-nl/p/{$group->id}/{$group->slug}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('amazonSearch', null));
    }
}
