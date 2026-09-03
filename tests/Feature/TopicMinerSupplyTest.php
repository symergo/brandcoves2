<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\Availability;
use App\Enums\Market;
use App\Enums\ProductStatus;
use App\Enums\Source;
use App\Models\GuideTopic;
use App\Models\Merchant;
use App\Models\Product;
use App\Models\ProductGroup;
use App\Models\SearchLog;
use App\Services\Guides\TopicMiner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * How far the miner counts before it stops.
 *
 * `availableProducts()` answers "could a guide be built from this topic", and
 * the answer is consumed by `score()`, which rejects anything under
 * MIN_PRODUCTS and then saturates — "30 products is not three times better than
 * 10". Everything above that ceiling was counted and thrown away.
 *
 * Counting it exhaustively is why the Daily Cove had not built in two of five
 * markets since 18 August 2026. The call runs once per candidate topic, and each
 * run was a full COUNT(*) over the presentable catalogue with a correlated
 * full-text EXISTS. Measured on production: 17 of 24 active database queries
 * were this one, queue workers idling at 0.1% CPU waiting on Postgres, and
 * BuildDailyEdition hitting its 900-second timeout nightly. The cost scaled with
 * catalogue size — en (16k groups) survived, be-nl (117k) never did.
 */
class TopicMinerSupplyTest extends TestCase
{
    use RefreshDatabase;

    private Merchant $merchant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->merchant = Merchant::create([
            'source' => Source::Awin->value,
            'external_id' => 'shop',
            'name' => 'Shop',
        ]);
    }

    private function matching(int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            $group = ProductGroup::create([
                'market' => Market::BeNl,
                'identity_key' => 'k'.bin2hex(random_bytes(5)),
                'identity_kind' => 'ean',
                'title' => "Koptelefoon model {$i}",
                'slug' => 'p-'.bin2hex(random_bytes(3)),
                'image_url' => 'https://img.test/x.jpg',
                'min_price' => 5000 + $i,
                'merchant_count' => 1,
                'in_stock' => true,
                'giftable' => true,
            ]);

            Product::create([
                'source' => Source::Awin,
                'market' => Market::BeNl,
                'merchant_id' => $this->merchant->id,
                'group_id' => $group->id,
                'external_id' => 'e'.bin2hex(random_bytes(5)),
                'title' => "Koptelefoon model {$i}",
                'price' => 5000 + $i,
                'currency' => 'EUR',
                'affiliate_url' => 'https://example.test/buy',
                'availability' => Availability::InStock,
                'status' => ProductStatus::Active,
                'identity_key' => $group->identity_key,
            ]);
        }
    }

    private function searchedFor(string $query, int $volume): void
    {
        DB::table('search_log')->insert([
            'market' => Market::BeNl->value,
            'query' => $query,
            // Not nullable, and the model owns how it is derived — recomputing
            // the sha256 here would be a second copy of that rule.
            'query_hash' => SearchLog::hashFor($query, Market::BeNl),
            'hour_bucket' => now()->subDay(),
            'search_count' => $volume,
            'zero_result_count' => 0,
        ]);
    }

    #[Test]
    public function supply_is_counted_only_as_far_as_the_score_can_tell(): void
    {
        // Comfortably past the saturation point, so an exhaustive count and a
        // capped one give visibly different answers.
        $this->matching(42);
        $this->searchedFor('koptelefoon', 30);

        app(TopicMiner::class)->mine(Market::BeNl);

        $topic = GuideTopic::query()
            ->where('market', Market::BeNl->value)
            ->where('topic', 'koptelefoon')
            ->first();

        $this->assertNotNull($topic, 'the topic was mined');

        /*
         * 30, not 42. The exact figure above the ceiling is never read: score()
         * saturates there, so counting past it buys nothing and costs a full
         * table scan on the largest market.
         *
         * If this assertion is ever "fixed" to expect 42, the Daily Cove stops
         * building on be-nl and be-fr within a day, and it fails as a timeout in
         * a queue worker rather than as anything resembling this test.
         */
        $this->assertSame(30, $topic->available_products);
    }

    #[Test]
    public function a_topic_below_the_floor_still_reports_its_real_supply(): void
    {
        // The cap must not disturb the small numbers, because the other consumer
        // of this value is a floor: score() returns zero under MIN_PRODUCTS, and
        // admin shows the count so a gap is visible as "searched for, 3 products".
        $this->matching(3);
        $this->searchedFor('koptelefoon', 30);

        app(TopicMiner::class)->mine(Market::BeNl);

        $topic = GuideTopic::query()
            ->where('market', Market::BeNl->value)
            ->where('topic', 'koptelefoon')
            ->first();

        $this->assertNotNull($topic);
        $this->assertSame(3, $topic->available_products);
        $this->assertSame(0.0, (float) $topic->score, 'below the floor, so not a guide');
    }
}
