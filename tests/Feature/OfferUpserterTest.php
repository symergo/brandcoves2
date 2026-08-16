<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\Availability;
use App\Enums\Market;
use App\Enums\Source;
use App\Services\Connectors\Offer;
use App\Services\Ingestion\OfferUpserter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Writing a chunk of offers into the catalogue.
 *
 * The load-bearing test here is
 * {@see a_feed_that_lists_the_same_product_twice_does_not_kill_the_run}.
 * Postgres refuses an `ON CONFLICT DO UPDATE` whose own batch repeats a
 * constrained key, and it refuses the **whole statement** — so one duplicated
 * product used to lose the entire chunk of tens of thousands of rows and end
 * the run with a cardinality violation.
 */
class OfferUpserterTest extends TestCase
{
    use RefreshDatabase;

    private function offer(string $externalId, int $price, string $title = 'Sony WF-C510'): Offer
    {
        return new Offer(
            source: Source::Bol,
            externalId: $externalId,
            market: Market::NlNl,
            title: $title,
            affiliateUrl: 'https://partner.bol.com/click/click?p=2&t=url&url=x',
            price: $price,
            merchantName: 'bol',
            merchantExternalId: 'bol',
            merchantDeepLink: 'https://www.bol.com/nl/nl/p/x/'.$externalId.'/',
            ean: '454873616'.substr($externalId, -4),
            availability: Availability::InStock,
        );
    }

    private function upserter(): OfferUpserter
    {
        return app(OfferUpserter::class);
    }

    #[Test]
    public function a_feed_that_lists_the_same_product_twice_does_not_kill_the_run(): void
    {
        /*
         * The exact shape that failed: a bol feed listing one product under two
         * categories, so the same (source, external_id, market) arrives twice in
         * one chunk. Before the fix this threw
         * "ON CONFLICT DO UPDATE command cannot affect row a second time" and
         * took every other row in the batch down with it.
         */
        $result = $this->upserter()->upsert([
            $this->offer('9300000186287122', 4300),
            $this->offer('9300000186287123', 4500, 'Sony WF-C510 Blauw'),
            $this->offer('9300000186287122', 4400),
        ]);

        $this->assertSame(2, $result['written']);
        $this->assertSame(2, DB::table('products')->count());
    }

    #[Test]
    public function the_last_occurrence_wins(): void
    {
        // What the upsert would have done had the rows arrived in two separate
        // statements: later in the file is the more recent record of the offer.
        $this->upserter()->upsert([
            $this->offer('9300000186287122', 4300),
            $this->offer('9300000186287122', 4400),
        ]);

        $this->assertSame(4400, DB::table('products')->value('price'));
    }

    #[Test]
    public function a_duplicate_does_not_stop_the_rest_of_the_chunk_being_written(): void
    {
        // The consequence that actually hurt: one bad pair losing tens of
        // thousands of good rows.
        $offers = [];

        foreach (range(1, 20) as $i) {
            $offers[] = $this->offer('93000001862871'.str_pad((string) $i, 2, '0', STR_PAD_LEFT), 1000 + $i);
        }

        // The same product again, in the middle of the chunk.
        $offers[] = $this->offer('9300000186287101', 9999);

        $this->upserter()->upsert($offers);

        $this->assertSame(20, DB::table('products')->count());
    }

    #[Test]
    public function a_repeat_across_two_runs_still_updates_rather_than_duplicating(): void
    {
        // The ordinary path, unchanged: deduplicating inside a batch must not
        // affect what happens between batches.
        $this->upserter()->upsert([$this->offer('9300000186287122', 4300)]);
        $this->upserter()->upsert([$this->offer('9300000186287122', 3900)]);

        $this->assertSame(1, DB::table('products')->count());
        $this->assertSame(3900, DB::table('products')->value('price'));
    }

    #[Test]
    public function the_same_external_id_in_two_markets_is_two_products(): void
    {
        /*
         * The key is (source, external_id, market), and bol reuses its product
         * ids across nl-nl and be-nl. Deduplicating on external_id alone would
         * silently drop one market's catalogue — invariant #2.
         */
        $nl = $this->offer('9300000186287122', 4300);

        $be = new Offer(
            source: Source::Bol,
            externalId: '9300000186287122',
            market: Market::BeNl,
            title: $nl->title,
            affiliateUrl: $nl->affiliateUrl,
            price: 4400,
            merchantName: 'bol',
            merchantExternalId: 'bol',
            merchantDeepLink: $nl->merchantDeepLink,
            ean: $nl->ean,
            availability: Availability::InStock,
        );

        $this->upserter()->upsert([$nl, $be]);

        $this->assertSame(2, DB::table('products')->count());
    }
}
