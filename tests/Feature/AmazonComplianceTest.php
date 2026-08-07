<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\Availability;
use App\Enums\Market;
use App\Enums\Source;
use App\Models\Merchant;
use App\Models\Product;
use App\Models\ProductGroup;
use App\Services\Alerts\AlertEligibility;
use App\Services\Connectors\Offer;
use App\Services\Ingestion\OfferUpserter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Amazon's terms are the tightest constraint on this product, and a violation
 * costs the Associates account — retroactively, across every link on the site.
 *
 * These tests exist so a feature cannot acquire a price history, an alert or an
 * email mention for an Amazon offer by accident. See
 * docs/features/amazon-compliance.md.
 */
class AmazonComplianceTest extends TestCase
{
    use RefreshDatabase;

    private function offer(Source $source, string $externalId, int $price): Offer
    {
        return new Offer(
            source: $source,
            externalId: $externalId,
            market: Market::BeNl,
            title: 'Sony WH-1000XM5 Koptelefoon',
            affiliateUrl: 'https://example.test/'.$externalId,
            price: $price,
            brand: 'Sony',
            merchantName: $source->label(),
            merchantExternalId: $source->value,
            merchantDeepLink: 'https://shop.test/'.$externalId,
            imageUrl: 'https://img.test/'.$externalId.'.jpg',
            ean: '4006381333931',
            availability: Availability::InStock,
        );
    }

    #[Test]
    public function amazon_prices_are_stored_because_storage_is_permitted(): void
    {
        app(OfferUpserter::class)->upsert([
            $this->offer(Source::Awin, 'aw-1', 34900),
            $this->offer(Source::Amazon, 'B08XYZ', 32900),
        ]);

        $this->assertSame(2, Product::query()->count());

        // Storing a price is NOT the restricted act. Both are recorded; what
        // Amazon prohibits is building a price-tracking feature on top, which
        // is gated on the read side.
        $this->assertSame(2, DB::table('price_history')->count());
    }

    #[Test]
    public function amazon_prices_never_appear_in_the_price_chart(): void
    {
        $group = $this->groupWith([Source::Awin, Source::Amazon]);

        foreach (Product::query()->get() as $product) {
            DB::table('price_history')->insert([
                'product_id' => $product->id,
                'price' => $product->price,
                'availability' => 'in_stock',
                'captured_at' => now(),
                'captured_on' => now()->toDateString(),
            ]);
        }

        $history = $this->get("/be-nl/p/{$group->id}/{$group->slug}")
            ->assertOk()
            ->viewData('page')['props']['history'];

        // Both sources have a stored sample, but the visitor-facing chart is
        // built from the Awin one alone.
        $this->assertSame(2, DB::table('price_history')->count());
        $this->assertCount(1, $history);
        $this->assertSame(
            Product::query()->where('source', Source::Awin)->value('price'),
            $history[0]['price'],
        );
    }

    #[Test]
    public function the_capability_matrix_is_what_the_policy_says(): void
    {
        // Read as documentation as much as assertion: the whole
        // Amazon-versus-everyone-else difference in one place.
        //
        // Storage is allowed; surfacing it as a tracking product is not.
        $this->assertTrue(Source::Amazon->allowsPriceStorage());
        $this->assertFalse(Source::Amazon->allowsPriceTracking());

        $this->assertFalse(Source::Amazon->allowsCatalogueStorage());
        $this->assertFalse(Source::Amazon->allowsPriceAlerts());
        $this->assertFalse(Source::Amazon->allowsEmail());
        $this->assertTrue(Source::Amazon->requiresPriceTimestamp());
        $this->assertNotNull(Source::Amazon->maxPriceAgeSeconds());

        foreach ([Source::Awin, Source::Bol] as $source) {
            $this->assertTrue($source->allowsPriceStorage());
            $this->assertTrue($source->allowsPriceTracking());
            $this->assertTrue($source->allowsCatalogueStorage());
            $this->assertTrue($source->allowsPriceAlerts());
            $this->assertTrue($source->allowsEmail());
            $this->assertFalse($source->requiresPriceTimestamp());
        }
    }

    #[Test]
    public function an_amazon_only_product_cannot_carry_an_alert(): void
    {
        $group = $this->groupWith([Source::Amazon]);

        $this->assertFalse(app(AlertEligibility::class)->isEligible($group));
    }

    #[Test]
    public function a_mixed_product_can_alert_but_only_on_the_permitted_offers(): void
    {
        $group = $this->groupWith([Source::Awin, Source::Amazon]);
        $eligibility = app(AlertEligibility::class);

        $this->assertTrue($eligibility->isEligible($group));

        // The alert watches Awin only. Promising to watch "the cheapest price"
        // while silently not watching one shop would be a lie by omission, so
        // the excluded source is surfaced for the UI to disclose.
        $watchable = $eligibility->watchableOffers($group);
        $this->assertCount(1, $watchable);
        $this->assertSame(Source::Awin, $watchable->first()->source);
        $this->assertSame(['Amazon'], $eligibility->excludedSources($group));
    }

    #[Test]
    public function a_non_amazon_product_alerts_normally(): void
    {
        $group = $this->groupWith([Source::Awin, Source::Bol]);
        $eligibility = app(AlertEligibility::class);

        $this->assertTrue($eligibility->isEligible($group));
        $this->assertCount(2, $eligibility->watchableOffers($group));
        $this->assertSame([], $eligibility->excludedSources($group));
    }

    /** @param list<Source> $sources */
    private function groupWith(array $sources): ProductGroup
    {
        $group = ProductGroup::create([
            'market' => Market::BeNl,
            'identity_key' => '4006381333931',
            'identity_kind' => 'ean',
            'title' => 'Sony WH-1000XM5',
            'slug' => 'sony-wh-1000xm5',
        ]);

        foreach ($sources as $i => $source) {
            $merchant = Merchant::create([
                'source' => $source,
                'external_id' => $source->value.$i,
                'name' => $source->label(),
            ]);

            Product::create([
                'source' => $source,
                'external_id' => $source->value.'-'.$i,
                'market' => Market::BeNl,
                'merchant_id' => $merchant->id,
                'group_id' => $group->id,
                'title' => 'Sony WH-1000XM5',
                'affiliate_url' => 'https://example.test/'.$i,
                'price' => 30000 + $i,
                'availability' => Availability::InStock,
            ]);
        }

        return $group->fresh();
    }
}
