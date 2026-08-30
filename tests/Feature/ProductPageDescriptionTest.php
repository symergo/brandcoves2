<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\Availability;
use App\Enums\Market;
use App\Enums\ProductStatus;
use App\Enums\Source;
use App\Models\Merchant;
use App\Models\Product;
use App\Models\ProductGroup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The long description reaching the product page.
 *
 * The picking rules have their own unit test; what this covers is the wiring —
 * that the section is built from the offers the page already loaded, and that
 * it is absent rather than empty when no shop supplied one.
 */
class ProductPageDescriptionTest extends TestCase
{
    use RefreshDatabase;

    private function group(): ProductGroup
    {
        return ProductGroup::factory()->create([
            'market' => Market::BeNl,
            'title' => 'Aurex AX-200 draadloze koptelefoon',
        ]);
    }

    private function offer(ProductGroup $group, ?string $description, Source $source = Source::Awin, string $shop = 'Coolblue'): Product
    {
        $merchant = Merchant::firstOrCreate(
            ['source' => $source->value, 'external_id' => $shop],
            ['name' => $shop],
        );

        return Product::create([
            'source' => $source,
            'market' => $group->market,
            'merchant_id' => $merchant->id,
            'group_id' => $group->id,
            'external_id' => 'x'.bin2hex(random_bytes(4)),
            'title' => $group->title,
            'description' => $description,
            'price' => 12900,
            'currency' => 'EUR',
            'affiliate_url' => 'https://example.test/buy',
            'availability' => Availability::InStock,
            'status' => ProductStatus::Active,
            'identity_key' => $group->identity_key,
        ]);
    }

    #[Test]
    public function it_quotes_the_shop_that_supplied_the_description(): void
    {
        $group = $this->group();
        $this->offer(
            $group,
            '<p>Draadloze koptelefoon met actieve ruisonderdrukking voor onderweg.</p>'
            .'<ul><li>Tot 30 uur speelduur op een volle lading</li>'
            .'<li>Bluetooth 5.2 met multipoint-verbinding</li></ul>',
        );

        $this->get("/be-nl/p/{$group->id}/{$group->slug}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('description.merchant', 'Coolblue')
                ->where('description.paragraphs.0', 'Draadloze koptelefoon met actieve ruisonderdrukking voor onderweg.')
                ->where('description.paragraphs.2', 'Bluetooth 5.2 met multipoint-verbinding')
            );
    }

    /**
     * No section rather than an empty one. Most of the catalogue's offers carry
     * nothing in this column, and a heading over blank space reads as a page
     * that failed to load.
     */
    #[Test]
    public function a_product_no_shop_described_gets_no_section(): void
    {
        $group = $this->group();
        $this->offer($group, null);

        $this->get("/be-nl/p/{$group->id}/{$group->slug}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('description', null));
    }
}
