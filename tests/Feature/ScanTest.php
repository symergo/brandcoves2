<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\Market;
use App\Models\ProductGroup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The barcode scanner.
 *
 * Nearly free by construction: `product_groups` is unique on
 * `(market, identity_key)` and for an EAN-grouped product that key IS the GTIN.
 * These tests mostly guard the edges around that one lookup — normalisation,
 * the market scope, and what happens on a miss.
 */
class ScanTest extends TestCase
{
    use RefreshDatabase;

    private function group(string $gtin, Market $market = Market::BeNl): ProductGroup
    {
        return ProductGroup::create([
            'market' => $market,
            'identity_key' => $gtin,
            'identity_kind' => 'ean',
            'title' => 'Sony WH-1000XM5',
            'slug' => 'sony-wh-1000xm5',
            'image_url' => 'https://img.test/x.jpg',
            'min_price' => 32999,
            'merchant_count' => 3,
            'in_stock' => true,
        ]);
    }

    #[Test]
    public function a_scan_finds_the_product(): void
    {
        $group = $this->group('4905524930184');

        $this->getJson('/be-nl/scan/4905524930184')
            ->assertOk()
            ->assertJsonPath('status', 'found')
            ->assertJsonPath('merchantCount', 3)
            ->assertJsonPath('url', "/be-nl/p/{$group->id}/sony-wh-1000xm5")
            // Where a scan actually lands. Someone holding a product up to a
            // camera has asked one question, and a card that makes them tap
            // again to answer it is a step that exists only because the code
            // was easier to write that way.
            ->assertJsonPath('searchUrl', '/be-nl/search?q=4905524930184');
    }

    #[Test]
    public function a_scan_always_searches_the_normalised_barcode(): void
    {
        $this->group('0049055249309');

        /*
         * The destination carries the NORMALISED GTIN, not what the camera
         * read.
         *
         * A camera reads a UPC-A as 12 digits and the catalogue stores 13, so
         * navigating to the raw read would land on an empty results page for
         * every American product — with the product sitting in the database.
         */
        $this->getJson('/be-nl/scan/049055249309')
            ->assertOk()
            ->assertJsonPath('searchUrl', '/be-nl/search?q=0049055249309');
    }

    #[Test]
    public function a_upc_a_is_normalised_to_the_stored_gtin(): void
    {
        /*
         * A camera reads a US barcode as 12 digits; the catalogue stores
         * GTIN-13. Without normalisation every American product would scan as
         * "not found" while sitting in the database.
         *
         * 049055249309 is a real UPC-A (check digit computed, not invented) and
         * 0049055249309 is what it normalises to — zero-padded, same product.
         */
        $this->group('0049055249309');

        $this->getJson('/be-nl/scan/049055249309')
            ->assertOk()
            ->assertJsonPath('status', 'found');
    }

    #[Test]
    public function a_misread_is_rejected_rather_than_reported_as_missing(): void
    {
        /*
         * The check digit is the whole point of having one.
         *
         * A camera that half-reads a barcode produces a plausible number, and
         * telling someone "we don't stock that" about a product they are
         * holding is worse than telling them the scan failed — one sends them
         * away, the other makes them try again.
         */
        $this->getJson('/be-nl/scan/4905524930185')
            ->assertStatus(422)
            ->assertJsonPath('status', 'invalid');
    }

    #[Test]
    public function a_miss_offers_a_search_rather_than_a_dead_end(): void
    {
        $response = $this->getJson('/be-nl/scan/4905524930184')
            ->assertOk()
            ->assertJsonPath('status', 'not_found');

        // Search treats a GTIN as an exact identity AND queries the live
        // sources, so a product we have never ingested can still turn up from
        // bol on the very next screen.
        $this->assertStringContainsString('4905524930184', $response->json('searchUrl'));
    }

    #[Test]
    public function a_scan_only_sees_its_own_market(): void
    {
        $this->group('4905524930184', Market::BeNl);

        // Identity is market-scoped: the same barcode in another market is a
        // different group with different tax, shipping and price. Returning the
        // Belgian one to a Spanish visitor is the same bug as letting a foreign
        // price masquerade as the cheapest.
        $this->getJson('/es/scan/4905524930184')
            ->assertOk()
            ->assertJsonPath('status', 'not_found');
    }

    #[Test]
    public function garbage_never_reaches_the_database(): void
    {
        // Rejected at the router, so a misread string of noise is a 404 rather
        // than a query.
        $this->get('/be-nl/scan/not-a-barcode')->assertNotFound();
        $this->get('/be-nl/scan/123')->assertNotFound();
    }

    #[Test]
    public function the_scanner_page_renders(): void
    {
        $this->get('/be-nl/scan')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Scan'));
    }
}
