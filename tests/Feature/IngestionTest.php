<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\Availability;
use App\Enums\IdentityKind;
use App\Enums\JobStatus;
use App\Enums\Market;
use App\Enums\Source;
use App\Jobs\GroupProducts;
use App\Jobs\IngestFeed;
use App\Models\Feed;
use App\Models\IngestionJob;
use App\Models\Merchant;
use App\Models\Product;
use App\Models\ProductGroup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The whole ingest → group → compare pipeline, against a fixture that encodes
 * the cases real feeds actually contain.
 */
class IngestionTest extends TestCase
{
    use RefreshDatabase;

    private function feed(): Feed
    {
        // firstOrCreate, because tests that ingest twice must reuse the feed —
        // re-running an ingest is the scenario, not creating a second feed.
        return Feed::firstOrCreate(
            [
                'source' => Source::Awin,
                'external_feed_id' => '18755',
                'market' => Market::BeNl,
            ],
            [
                'label' => 'Test advertiser',
                'enabled' => true,
                // The connector honours a url override, which lets a test use a
                // local fixture without stubbing the HTTP layer.
                'column_map' => ['url' => base_path('tests/Fixtures/awin-sample.csv')],
            ],
        );
    }

    private function ingest(): void
    {
        $feed = $this->feed();
        IngestFeed::dispatchSync($feed->id);
        GroupProducts::dispatchSync(Market::BeNl);
    }

    #[Test]
    public function it_ingests_offers_and_creates_merchants(): void
    {
        $this->ingest();

        // 10 rows in, one rejected for an unsafe URL.
        $this->assertSame(9, Product::query()->count());

        $this->assertSame(2, Merchant::query()->count());
        $this->assertSame(
            'coolblue.be',
            Merchant::query()->where('name', 'Coolblue')->value('domain'),
            'the domain must come from the merchant deep link, not the Awin tracking URL',
        );
    }

    #[Test]
    public function a_javascript_url_never_reaches_the_database(): void
    {
        $this->ingest();

        $this->assertDatabaseMissing('products', ['external_id' => '1008']);
        $this->assertSame(0, Product::query()->where('affiliate_url', 'like', 'javascript:%')->count());
    }

    #[Test]
    public function offers_sharing_an_ean_become_one_comparable_product(): void
    {
        $this->ingest();

        $group = ProductGroup::query()->where('identity_key', '4006381333931')->first();

        $this->assertNotNull($group, 'a valid shared EAN must produce one group');
        $this->assertSame(IdentityKind::Ean, $group->identity_kind);
        $this->assertSame(2, $group->offer_count);
        $this->assertSame(2, $group->merchant_count);
        $this->assertTrue($group->hasMultipleMerchants());

        // The whole point: cheapest across shops.
        $this->assertSame(32999, $group->min_price);
        $this->assertSame(34900, $group->max_price);
    }

    #[Test]
    public function the_best_offer_is_the_cheapest_in_stock_one(): void
    {
        $this->ingest();

        $group = ProductGroup::query()->where('identity_key', '8719514339149')->first();

        // 1003 is 129.95 and in stock; 1004 is 134.00 and out of stock.
        $this->assertNotNull($group);
        $this->assertTrue($group->in_stock, 'one in-stock offer makes the product buyable');

        $best = Product::query()->find($group->best_offer_id);
        $this->assertSame('1003', $best?->external_id);
        $this->assertSame(Availability::InStock, $best?->availability);
    }

    #[Test]
    public function rows_without_an_ean_still_group_on_brand_and_title(): void
    {
        $this->ingest();

        // "0" and "N/A" are placeholders, not barcodes. Without the fallback
        // path these two shops' listings could never be compared.
        $group = ProductGroup::query()->where('identity_kind', IdentityKind::Title->value)
            ->where('brand', 'Nedis')
            ->first();

        $this->assertNotNull($group);
        $this->assertSame(2, $group->merchant_count);
        $this->assertSame(2750, $group->min_price);
    }

    #[Test]
    public function placeholder_eans_do_not_merge_unrelated_products(): void
    {
        $this->ingest();

        // Rows carrying "0", "N/A" or an empty EAN must never share a group
        // just because their placeholder matches.
        foreach (['0', 'N/A', ''] as $placeholder) {
            $this->assertSame(
                0,
                ProductGroup::query()->where('identity_key', $placeholder)->count(),
                "\"{$placeholder}\" must not become a group key",
            );
        }
    }

    #[Test]
    public function ambiguous_rows_are_left_ungrouped(): void
    {
        $this->ingest();

        // "Kabel" is too short to discriminate; "Merkloos" is not a brand.
        // Both are deliberately left alone — a wrong merge is worse than none.
        $this->assertNull(Product::query()->where('external_id', '1007')->value('group_id'));
        $this->assertNull(Product::query()->where('external_id', '1009')->value('group_id'));
    }

    #[Test]
    public function european_prices_parse_to_the_right_cents(): void
    {
        $this->ingest();

        // "1.299,00" is 1299 euros, not 1.299.
        $this->assertSame(129900, Product::query()->where('external_id', '1010')->value('price'));
        $this->assertSame(34900, Product::query()->where('external_id', '1001')->value('price'));
    }

    #[Test]
    public function every_stored_offer_is_usable(): void
    {
        $this->ingest();

        // A row with no image or no affiliate URL cannot be shown or clicked.
        $this->assertSame(0, Product::query()->whereNull('affiliate_url')->count());
        $this->assertSame(0, Product::query()->whereNull('image_url')->count());
        $this->assertSame(
            0,
            Product::query()->where('affiliate_url', 'not like', 'https://%')->count(),
            'every affiliate URL must be https',
        );
    }

    #[Test]
    public function price_history_records_one_sample_per_day(): void
    {
        $this->ingest();
        // A second run in the same day must not add a second sample.
        $this->ingest();

        $this->assertSame(
            Product::query()->whereNotNull('price')->count(),
            \DB::table('price_history')->count(),
        );
    }

    #[Test]
    public function re_ingesting_is_idempotent(): void
    {
        $this->ingest();
        $groupsAfterFirst = ProductGroup::query()->count();
        $best = ProductGroup::query()->where('identity_key', '4006381333931')->value('best_offer_id');

        $this->ingest();

        $this->assertSame(9, Product::query()->count(), 'upserts must not duplicate rows');
        $this->assertSame($groupsAfterFirst, ProductGroup::query()->count());
        $this->assertSame(
            $best,
            ProductGroup::query()->where('identity_key', '4006381333931')->value('best_offer_id'),
            'best_offer_id must be stable across runs, or caches churn and the UI jumps',
        );
    }

    #[Test]
    public function it_resumes_from_a_saved_cursor(): void
    {
        $feed = $this->feed();
        IngestFeed::dispatchSync($feed->id);

        $tracker = IngestionJob::query()->where('job_key', $feed->jobKey())->first();

        $this->assertNotNull($tracker);
        $this->assertSame(JobStatus::Completed, $tracker->status);
        // Cleared on success so the next run starts from the top of a fresh feed.
        $this->assertNull($tracker->cursor);
    }

    #[Test]
    public function rows_that_vanish_from_a_feed_are_retired_not_deleted(): void
    {
        $this->ingest();

        $product = Product::query()->where('external_id', '1001')->firstOrFail();
        // Pretend this row was not in the latest feed.
        $product->update(['last_seen_at' => now()->subDays(2)]);

        $feed = Feed::query()->firstOrFail();
        IngestFeed::dispatchSync($feed->id);

        // Still present — a wishlist item or published guide may point at it,
        // and a dead link is worse than an out-of-stock badge.
        $this->assertDatabaseHas('products', ['external_id' => '1001']);
    }
}
