<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\Availability;
use App\Enums\JobStatus;
use App\Enums\Market;
use App\Enums\ProductStatus;
use App\Enums\Source;
use App\Jobs\ClassifyGiftability;
use App\Jobs\GroupProducts;
use App\Jobs\IngestFeed;
use App\Jobs\RefreshBrandStats;
use App\Models\Feed;
use App\Models\IngestionJob;
use App\Models\Product;
use App\Services\Connectors\ConnectorRegistry;
use App\Services\Connectors\Offer;
use App\Services\Ingestion\OfferUpserter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The ingest writes only what changed, and a resumed run keeps its start.
 *
 * Two changes of 2026-09-28 that depend on each other. The upsert no longer
 * rewrites an offer that arrives identical, so `last_seen_at` cannot say
 * whether the feed still lists it; what a run saw is kept in
 * `ingestion_seen_offers` instead. And a run cut off by a deploy resumes with
 * the start time of its first attempt, where it used to take a new one and
 * retire everything the first attempt had committed.
 */
class IngestionWritesOnlyChangesTest extends TestCase
{
    use RefreshDatabase;

    private function offer(int $price, string $title = 'Sony WH-1000XM5'): Offer
    {
        return new Offer(
            source: Source::Awin,
            externalId: 'unchanged-1',
            market: Market::BeNl,
            title: $title,
            affiliateUrl: 'https://example.test/unchanged-1',
            price: $price,
            merchantName: 'Shop',
            merchantExternalId: 'shop',
            availability: Availability::InStock,
        );
    }

    #[Test]
    public function an_offer_that_arrives_unchanged_is_not_rewritten(): void
    {
        $upserter = app(OfferUpserter::class);
        $upserter->upsert([$this->offer(34900)]);

        $long_ago = now()->subDays(3)->startOfSecond();
        DB::table('products')->update(['updated_at' => $long_ago, 'last_seen_at' => $long_ago]);
        $ctid = DB::selectOne("SELECT ctid::text AS c FROM products WHERE external_id = 'unchanged-1'")->c;

        $upserter->upsert([$this->offer(34900)]);

        $row = Product::query()->where('external_id', 'unchanged-1')->firstOrFail();
        $this->assertTrue($row->updated_at->equalTo($long_ago), 'an identical offer must not be written');
        // Not even a new row version: the physical location is unchanged.
        $this->assertSame($ctid, DB::selectOne("SELECT ctid::text AS c FROM products WHERE external_id = 'unchanged-1'")->c);

        // A change in price is written, with the price bookkeeping as before.
        $upserter->upsert([$this->offer(29900)]);
        $row->refresh();
        $this->assertSame(29900, $row->price);
        $this->assertSame(34900, $row->previous_price);
        $this->assertTrue($row->updated_at->greaterThan($long_ago));

        // So is a change of text alone.
        DB::table('products')->update(['updated_at' => $long_ago]);
        $upserter->upsert([$this->offer(29900, 'Sony WH-1000XM5 Zwart')]);
        $this->assertSame('Sony WH-1000XM5 Zwart', $row->refresh()->title);
        $this->assertTrue($row->updated_at->greaterThan($long_ago));
    }

    #[Test]
    public function an_unchanged_offer_the_feed_still_lists_stays_active_and_a_missing_one_retires(): void
    {
        $feed = $this->feed();
        IngestFeed::dispatchSync($feed->id);

        // An offer from this feed that the file does not list (any more).
        $this->plantUnlisted($feed);

        // Everything a week old: the second run changes nothing in them, so
        // nothing moves their dates. Under the old rule every one would retire.
        DB::table('products')->update(['last_seen_at' => now()->subWeek(), 'updated_at' => now()->subWeek()]);

        IngestFeed::dispatchSync($feed->id);

        $this->assertSame(ProductStatus::Stale, Product::query()->where('external_id', 'no-longer-listed')->firstOrFail()->status);
        $this->assertSame(0, Product::query()->where('feed_id', $feed->id)->where('external_id', '<>', 'no-longer-listed')->where('status', ProductStatus::Stale->value)->count());
        $this->assertSame(0, DB::table('ingestion_seen_offers')->count(), 'the seen list lives for one run');
    }

    #[Test]
    public function a_resumed_ingest_keeps_its_run_start_and_retires_nothing_it_saw(): void
    {
        config(['giftcoves.connectors.awin.chunk_size' => 2]);
        $feed = $this->feed();

        // An offer of this feed from before the run, which the file lacks.
        IngestFeed::dispatchSync($feed->id);
        $this->plantUnlisted($feed);

        // First attempt: a deploy stops it after the first chunk.
        $this->runOnce($feed, stopAfterChunks: 1);

        $tracker = IngestionJob::query()->where('job_key', $feed->jobKey())->firstOrFail();
        $start = $tracker->cursor['run_started_at'] ?? null;
        $this->assertNotNull($start);
        $this->assertSame(JobStatus::Pending, $tracker->status);
        $this->assertGreaterThan(0, (int) $tracker->cursor['row']);

        // Second attempt, a few minutes later: stopped again, same start.
        $this->travel(5)->minutes();
        $this->runOnce($feed, stopAfterChunks: 1);
        $this->assertSame($start, $tracker->refresh()->cursor['run_started_at']);

        // Third attempt finishes.
        $this->travel(5)->minutes();
        $this->runOnce($feed, stopAfterChunks: null);

        $this->assertSame(JobStatus::Completed, $tracker->refresh()->status);
        $this->assertSame(ProductStatus::Stale, Product::query()->where('external_id', 'no-longer-listed')->firstOrFail()->status);
        $this->assertSame(
            0,
            Product::query()->where('feed_id', $feed->id)->where('external_id', '<>', 'no-longer-listed')->where('status', ProductStatus::Stale->value)->count(),
            'an offer committed by an earlier attempt of the same run must not be retired',
        );
    }

    #[Test]
    public function a_seen_list_emptied_by_a_crash_retires_nothing(): void
    {
        config(['giftcoves.connectors.awin.chunk_size' => 2]);
        $feed = $this->feed();
        IngestFeed::dispatchSync($feed->id);

        $this->runOnce($feed, stopAfterChunks: 1);

        // Postgres empties an UNLOGGED table after a crash.
        DB::table('ingestion_seen_offers')->delete();

        $this->runOnce($feed, stopAfterChunks: null);

        $this->assertSame(0, Product::query()->where('status', ProductStatus::Stale->value)->count());
    }

    #[Test]
    public function grouping_classifying_and_brand_stats_leave_unchanged_rows_alone(): void
    {
        IngestFeed::dispatchSync($this->feed()->id);
        GroupProducts::dispatchSync(Market::BeNl);
        ClassifyGiftability::dispatchSync(Market::BeNl);
        RefreshBrandStats::dispatchSync(Market::BeNl);

        $long_ago = now()->subDays(3)->startOfSecond();
        DB::table('product_groups')->update(['updated_at' => $long_ago]);
        DB::table('brand_stats')->update(['computed_at' => $long_ago]);

        GroupProducts::dispatchSync(Market::BeNl);
        ClassifyGiftability::dispatchSync(Market::BeNl);
        RefreshBrandStats::dispatchSync(Market::BeNl);

        $this->assertSame(0, DB::table('product_groups')->where('updated_at', '<>', $long_ago)->count(), 'nothing changed, so no group is rewritten');
        $this->assertSame(0, DB::table('brand_stats')->where('computed_at', '<>', $long_ago)->count(), 'nor any brand');

        // A price that moves reaches its group, and only its group.
        $offer = Product::query()->whereNotNull('group_id')->where('status', ProductStatus::Active->value)->orderBy('price')->firstOrFail();
        DB::table('products')->where('id', $offer->id)->update(['price' => max(1, $offer->price - 500)]);

        GroupProducts::dispatchSync(Market::BeNl);

        $this->assertSame(1, DB::table('product_groups')->where('updated_at', '<>', $long_ago)->count());
        $this->assertSame($offer->group_id, DB::table('product_groups')->where('updated_at', '<>', $long_ago)->value('id'));
    }

    private function plantUnlisted(Feed $feed): void
    {
        app(OfferUpserter::class)->upsert([new Offer(
            source: Source::Awin,
            externalId: 'no-longer-listed',
            market: Market::BeNl,
            title: 'Something the feed dropped',
            affiliateUrl: 'https://example.test/dropped',
            price: 1000,
            merchantName: 'Shop',
            merchantExternalId: 'shop',
            availability: Availability::InStock,
        )], $feed);
    }

    private function runOnce(Feed $feed, ?int $stopAfterChunks): void
    {
        $upserter = new class($stopAfterChunks) extends OfferUpserter
        {
            private int $calls = 0;

            public function __construct(private readonly ?int $stopAfter) {}

            public function upsert(array $offers, ?Feed $feed = null): array
            {
                $result = parent::upsert($offers, $feed);

                if ($this->stopAfter !== null && ++$this->calls >= $this->stopAfter) {
                    IngestFeed::requestStop();
                }

                return $result;
            }
        };

        (new IngestFeed($feed->id))->handle(app(ConnectorRegistry::class), $upserter);
    }

    private function feed(): Feed
    {
        return Feed::firstOrCreate(
            ['source' => Source::Awin, 'external_feed_id' => '18755', 'market' => Market::BeNl],
            [
                'label' => 'Test advertiser',
                'enabled' => true,
                'column_map' => ['url' => base_path('tests/Fixtures/awin-sample.csv')],
            ],
        );
    }
}
