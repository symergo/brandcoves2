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
use App\Services\Connectors\Offer;
use App\Services\Ingestion\IncomingGrouper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Offers that arrive outside a feed run join their groups, found by source.
 *
 * The grouper is shared by live search and the bol page import. Until
 * 2026-09-14 its statements found the offers by id and market alone, which the
 * only index on the id (`source, external_id, market`) cannot seek into: every
 * first search for a term walked the whole index three times, 7 to over 45
 * seconds on production. These tests pin the behaviour the fix must keep and
 * the one it must change.
 */
class IncomingGrouperTest extends TestCase
{
    use RefreshDatabase;

    private function merchant(Source $source): Merchant
    {
        return Merchant::create([
            'source' => $source->value,
            'external_id' => $source->value.'-shop',
            'name' => ucfirst($source->value),
        ]);
    }

    /** A row as the upserter leaves it: written, and not yet grouped unless a group is given. */
    private function product(Merchant $merchant, Source $source, string $externalId, string $identity, int $price, ?int $groupId = null): Product
    {
        return Product::create([
            'source' => $source,
            'market' => Market::BeNl,
            'merchant_id' => $merchant->id,
            'group_id' => $groupId,
            'external_id' => $externalId,
            'identity_kind' => 'ean',
            'identity_key' => $identity,
            'title' => "Product {$identity}",
            'merchant_category' => 'Divers',
            'price' => $price,
            'currency' => 'EUR',
            'affiliate_url' => 'https://example.test/buy',
            'availability' => Availability::InStock,
            'status' => ProductStatus::Active,
        ]);
    }

    private function offer(Source $source, string $externalId): Offer
    {
        return new Offer(
            source: $source,
            externalId: $externalId,
            market: Market::BeNl,
            title: 'Incoming',
            affiliateUrl: 'https://example.test/buy',
        );
    }

    private function group(string $identity): ProductGroup
    {
        return ProductGroup::create([
            'market' => Market::BeNl,
            'identity_key' => $identity,
            'identity_kind' => 'ean',
            'title' => "Product {$identity}",
            'slug' => 'p-'.$identity,
        ]);
    }

    /**
     * The job itself, unchanged by the fix: a new offer joins the group of its
     * identity, and the counts that follow include every shop's offers, not
     * only the source that brought this one in.
     */
    #[Test]
    public function a_new_offer_joins_its_group_and_the_counts_include_every_shop(): void
    {
        $group = $this->group('4000000000001');
        $this->product($this->merchant(Source::Ebay), Source::Ebay, 'e-1', '4000000000001', 3000, $group->id);
        $incoming = $this->product($this->merchant(Source::Bol), Source::Bol, 'b-1', '4000000000001', 2500);

        app(IncomingGrouper::class)->attach(Market::BeNl, [$this->offer(Source::Bol, 'b-1')]);

        $this->assertSame($group->id, $incoming->fresh()->group_id);

        $group->refresh();
        $this->assertSame(2, $group->offer_count);
        $this->assertSame(2, $group->merchant_count, 'the shop count is every shop, whichever source brought this one in');
        $this->assertSame(2500, $group->min_price);
    }

    /**
     * What the fix changes: an id is only unique within its source.
     *
     * An eBay row that happens to share a bol offer's number used to be swept
     * in by the bol offer's arrival — a group created for it outside the
     * nightly run and linked. Found by source, it is left exactly as it was.
     */
    #[Test]
    public function another_shops_row_with_the_same_id_is_left_alone(): void
    {
        $ebay = $this->product($this->merchant(Source::Ebay), Source::Ebay, 'SAME', '4000000000002', 3000);
        $bol = $this->product($this->merchant(Source::Bol), Source::Bol, 'SAME', '4000000000003', 2500);

        app(IncomingGrouper::class)->attach(Market::BeNl, [$this->offer(Source::Bol, 'SAME')]);

        $this->assertNotNull($bol->fresh()->group_id, 'the bol offer is grouped');
        $this->assertNull($ebay->fresh()->group_id, 'the eBay row was not sent and must not be touched');
        $this->assertFalse(
            ProductGroup::query()->where('identity_key', '4000000000002')->exists(),
            'no group is created for a row nobody sent',
        );
    }

    /** Offers from two sources in one batch are each found under their own. */
    #[Test]
    public function a_batch_from_two_sources_groups_both(): void
    {
        $bol = $this->product($this->merchant(Source::Bol), Source::Bol, 'b-9', '4000000000009', 2000);
        $ebay = $this->product($this->merchant(Source::Ebay), Source::Ebay, 'e-9', '4000000000009', 2200);

        app(IncomingGrouper::class)->attach(Market::BeNl, [
            $this->offer(Source::Bol, 'b-9'),
            $this->offer(Source::Ebay, 'e-9'),
        ]);

        $this->assertNotNull($bol->fresh()->group_id);
        $this->assertSame($bol->fresh()->group_id, $ebay->fresh()->group_id, 'one identity, one group, two shops');
        $this->assertSame(2, ProductGroup::find($bol->fresh()->group_id)->merchant_count);
    }
}
