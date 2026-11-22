<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\Availability;
use App\Enums\Market;
use App\Enums\Source;
use App\Jobs\FetchEbayItemDetails;
use App\Models\ProductGroup;
use App\Models\WishlistItem;
use App\Services\Connectors\ConnectorRegistry;
use App\Services\Connectors\Ebay\EbayConnector;
use App\Services\Connectors\Ebay\EbayItemDetails;
use App\Services\Connectors\LiveConnector;
use App\Services\Connectors\Offer;
use App\Services\Identity\GroupMerger;
use App\Services\Identity\IdentityResolver;
use App\Services\Ingestion\IncomingGrouper;
use App\Services\Search\SearchGenerations;
use App\Services\Search\SearchQuery;
use App\Services\Search\SearchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * eBay listings get their brand, barcode and part number from the item detail, and become
 * products (2026-11-22: Spain had 520 eBay offers and no products, so "lego" found nothing).
 */
class EbayItemDetailsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'giftcoves.connectors.ebay.enabled' => true,
            'giftcoves.connectors.ebay.client_id' => 'test-id',
            'giftcoves.connectors.ebay.client_secret' => 'test-secret',
            'giftcoves.connectors.ebay.marketplace' => ['es' => 'EBAY_ES'],
            'giftcoves.connectors.ebay.campaign_id' => ['EBAY_ES' => '5339199870'],
            'giftcoves.connectors.ebay.details_per_day' => 2000,
        ]);

        foreach (['search', 'item'] as $bucket) {
            Redis::del("bc:ratelimit:ebay:{$bucket}", "bc:ratelimit:ebay:{$bucket}:cooldown");
        }
        Cache::forget('bc:ebay:token');
    }

    #[Test]
    public function the_detail_gives_brand_and_part_number_and_no_brand_means_none(): void
    {
        $this->assertSame(
            ['brand' => 'LEGO', 'gtin' => null, 'mpn' => '40918'],
            EbayItemDetails::parse(['brand' => 'LEGO', 'mpn' => '40918', 'gtin' => 'NA']),
        );

        // A knock-off: "no brand", and "compatible with LEGO" elsewhere, which is not its brand
        $this->assertSame(
            ['brand' => null, 'gtin' => null, 'mpn' => null],
            EbayItemDetails::parse(['brand' => '- Sans marque/Générique -', 'localizedAspects' => [
                ['name' => 'Marque compatible', 'value' => 'LEGO'],
                ['name' => 'Numéro de pièce fabricant', 'value' => 'Non applicable'],
            ]]),
        );

        // Only among the item specifics, and a real barcode
        $this->assertSame(
            ['brand' => 'Playmobil', 'gtin' => '4008789709592', 'mpn' => '70959'],
            EbayItemDetails::parse(['gtin' => '4008789709592', 'localizedAspects' => [
                ['name' => 'Marca', 'value' => 'Playmobil'],
                ['name' => 'MPN', 'value' => '70959'],
            ]]),
        );
    }

    #[Test]
    public function a_listing_gets_its_details_an_identity_and_a_product(): void
    {
        $id = 'v1|123456789012|0';
        $this->storeOffer($id, 'LEGO 40918 Winter Snow Globe GWP exclusivo nuevo');
        $this->fakeEbay(['itemId' => $id, 'title' => 'LEGO 40918 Winter Snow Globe', 'brand' => 'LEGO', 'mpn' => '40918']);

        $before = SearchGenerations::term(Market::Es, 'lego');

        (new FetchEbayItemDetails(Market::Es, [$id], 'lego'))->handle(app(EbayConnector::class), app(EbayItemDetails::class), app(IncomingGrouper::class), app(GroupMerger::class));

        // The search that queued it cached an empty result before the product existed: retired now
        $this->assertNotSame($before, SearchGenerations::term(Market::Es, 'lego'));

        $offer = DB::table('products')->where('external_id', $id)->first();
        $this->assertSame('LEGO', $offer->brand);
        $this->assertSame('40918', $offer->mpn);
        $this->assertNotNull($offer->identity_key);
        $this->assertNotNull($offer->group_id, 'grouped, so search can show it');
        $this->assertSame(1, DB::table('product_groups')->where('market', 'es')->count());
        $this->assertSame('LEGO', DB::table('ebay_item_details')->where('external_id', $id)->value('brand'));
    }

    #[Test]
    public function an_ended_listing_is_remembered_and_never_asked_again(): void
    {
        $id = 'v1|999999999999|0';
        $this->storeOffer($id, 'LEGO Ideas faro motorizado');
        Http::fake([
            'api.ebay.com/identity/*' => Http::response(['access_token' => 'tok', 'expires_in' => 7200]),
            'api.ebay.com/buy/*' => Http::response(['errors' => [['errorId' => 11001]]], 404),
        ]);

        $this->runJob([$id]);
        $this->runJob([$id]);

        $this->assertSame(1, DB::table('ebay_item_details')->where('external_id', $id)->whereNull('brand')->count());
        Http::assertSentCount(2); // one token, one item: the second run asked nothing
        $this->assertNull(DB::table('products')->where('external_id', $id)->value('identity_key'));
    }

    #[Test]
    public function the_daily_cap_is_kept(): void
    {
        config(['giftcoves.connectors.ebay.details_per_day' => 1]);
        $this->storeOffer('v1|1|0', 'LEGO Technic 42115 Lamborghini');
        $this->storeOffer('v1|2|0', 'LEGO Icons 11375 Ferrari');
        $this->fakeEbay(['brand' => 'LEGO']);

        $this->runJob(['v1|1|0', 'v1|2|0']);

        $this->assertSame(1, DB::table('ebay_item_details')->count());
    }

    #[Test]
    public function a_later_search_result_keeps_the_details(): void
    {
        DB::table('ebay_item_details')->insert(['external_id' => 'v1|5|0', 'brand' => 'LEGO', 'gtin' => null, 'mpn' => '21335', 'fetched_at' => now()]);
        $offer = new Offer(Source::Ebay, 'v1|5|0', Market::Es, 'LEGO 21335 Ideas faro', 'https://www.ebay.es/itm/5');

        [$applied] = app(EbayItemDetails::class)->apply([$offer]);

        $this->assertSame('LEGO', $applied->brand);
        $this->assertSame('21335', $applied->mpn);
    }

    #[Test]
    public function a_search_queues_details_only_for_listings_without_brand_or_barcode(): void
    {
        Queue::fake();
        app(ConnectorRegistry::class)->registerLive(new class implements LiveConnector
        {
            public function source(): Source
            {
                return Source::Ebay;
            }

            public function supports(Market $market): bool
            {
                return true;
            }

            public function isCoolingDown(): bool
            {
                return false;
            }

            public function search(string $query, Market $market, int $limit = 24): array
            {
                return [
                    new Offer(Source::Ebay, 'v1|11|0', $market, 'LEGO 40918 Winter Snow Globe', 'https://www.ebay.es/itm/11', price: 4999, imageUrl: 'https://i.ebayimg.com/11.jpg', availability: Availability::InStock),
                    new Offer(Source::Ebay, 'v1|12|0', $market, 'LEGO 21335 Faro', 'https://www.ebay.es/itm/12', price: 19999, imageUrl: 'https://i.ebayimg.com/12.jpg', ean: '5702016912371', availability: Availability::InStock),
                ];
            }

            public function fetchById(string $externalId, Market $market): ?Offer
            {
                return null;
            }

            public function refresh(string $externalId, ?string $ean, Market $market): ?Offer
            {
                return null;
            }
        });

        $result = app(SearchService::class)->search(new SearchQuery(market: Market::Es, term: 'lego', logged: false), waitForLive: true);

        // Both in the same call: the bare listing as a product of its own, not waiting for its detail
        $this->assertCount(2, $result->groups->items());
        $this->assertSame(1, DB::table('product_groups')->where('market', 'es')->where('identity_kind', 'listing')->count());

        Queue::assertPushed(FetchEbayItemDetails::class, fn (FetchEbayItemDetails $job): bool => $job->market === Market::Es && $job->externalIds === ['v1|11|0'] && $job->term === 'lego');
    }

    #[Test]
    public function a_stored_listing_without_a_brand_becomes_a_product_of_its_own(): void
    {
        $id = 'v1|888|0';
        $this->storeOffer($id, 'Ladrillo de construcción jardín botánico compatible 21353');
        $this->fakeEbay(['itemId' => $id, 'brand' => '- Sans marque/Générique -']);

        $this->runJob([$id]);

        $offer = DB::table('products')->where('external_id', $id)->first();
        $this->assertSame(IdentityResolver::listing('ebay', $id)->key, $offer->identity_key);
        $this->assertNotNull($offer->group_id, 'shown, as a product of its own and never as LEGO');
        $this->assertSame('listing', DB::table('product_groups')->where('id', $offer->group_id)->value('identity_kind'));
    }

    #[Test]
    public function a_listing_product_merges_into_its_real_product_and_takes_what_was_saved_to_it(): void
    {
        $id = 'v1|777|0';
        $this->storeOffer($id, 'LEGO 40918 Winter Snow Globe GWP exclusivo nuevo');
        $listing = IdentityResolver::listing('ebay', $id);
        DB::table('products')->where('external_id', $id)->update(['identity_key' => $listing->key, 'identity_kind' => 'listing']);
        app(IncomingGrouper::class)->attachIds(Market::Es, 'ebay', [$id]);
        $listingProduct = ProductGroup::query()->where('identity_key', $listing->key)->sole();

        // Saved to somebody's list in the minute before its detail arrived
        $saved = WishlistItem::factory()->of($listingProduct)->create();

        $this->fakeEbay(['itemId' => $id, 'brand' => 'LEGO', 'mpn' => '40918']);
        $this->runJob([$id]);

        $real = ProductGroup::query()->where('market', 'es')->where('identity_kind', 'title')->sole();
        $this->assertSame($real->id, DB::table('products')->where('external_id', $id)->value('group_id'));
        $this->assertSame($real->id, $saved->fresh()->group_id, 'the list item moved along, not left on an empty product');
        $this->assertSame($real->id, $listingProduct->fresh()->merged_into_id, 'its old address answers 301');
    }

    /** @param  array<string, mixed>  $item */
    private function fakeEbay(array $item): void
    {
        Http::fake([
            'api.ebay.com/identity/*' => Http::response(['access_token' => 'tok', 'expires_in' => 7200]),
            'api.ebay.com/buy/*' => Http::response($item),
        ]);
    }

    /** @param  list<string>  $ids */
    private function runJob(array $ids): void
    {
        dispatch_sync(new FetchEbayItemDetails(Market::Es, $ids));
    }

    private function storeOffer(string $externalId, string $title): void
    {
        DB::table('products')->insert([
            'source' => 'ebay', 'external_id' => $externalId, 'market' => 'es', 'title' => $title,
            'affiliate_url' => 'https://www.ebay.es/itm/'.md5($externalId), 'price' => 4999, 'currency' => 'EUR',
            'image_url' => 'https://i.ebayimg.com/x.jpg', 'status' => 'active',
            'first_seen_at' => now(), 'last_seen_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
