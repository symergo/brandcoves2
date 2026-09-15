<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\Market;
use App\Models\GuideTopic;
use App\Models\ProductGroup;
use App\Models\SearchLog;
use App\Services\Guides\SeasonalTopics;
use App\Services\Guides\TopicMiner;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Coves commissioned by the calendar.
 *
 * The property under test is a timing one. A search log cannot see a season
 * coming: barbecue demand peaks in June, so a log-only queue commissions the
 * barbecue Cove in July and it first earns real traffic the following May.
 * Halloween is worse — three weeks of demand, so by the time the log knows, it is
 * over.
 *
 * `SeasonalTopics::seed()` stores one row per season with a window that opens
 * *before* the season, and `opening()` is how the editorial calendar
 * (`bc:plan-coves`, through `SeasonalSeries::plan()`) reads those rows back.
 * These tests pin what `opening()` offers and what it holds back: a window that
 * has not opened, a window that wraps the year end, a topic the catalogue cannot
 * fill, one an editor rejected, and one the builder has just failed on.
 */
class SeasonalCoveTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Enough products for a topic to be buildable.
     *
     * Titles contain the head noun the seasonal matcher looks for, because that
     * is how a real feed reads — "Weber gasbarbecue", not "barbecue".
     */
    private function seedProducts(string $noun, int $count = 6, ?Market $market = null): void
    {
        $market ??= Market::BeNl;

        for ($i = 0; $i < $count; $i++) {
            ProductGroup::create([
                'market' => $market->value,
                'identity_key' => "seasonal-{$noun}-{$i}",
                'identity_kind' => 'title',
                'title' => "Merk {$noun} model {$i}",
                'slug' => "{$noun}-{$i}",
                'brand' => 'Merk',
                'image_url' => "https://example.test/{$noun}-{$i}.jpg",
                'min_price' => 4900,
                'max_price' => 8900,
                'previous_price' => 8900,
                'offer_count' => 1,
                'merchant_count' => 1,
                'in_stock' => true,
            ]);
        }
    }

    /**
     * The seasons the calendar is offered on this day, by topic.
     *
     * A horizon of zero days by default: only windows already open on `$on`
     * count, which is the question most of these tests are about.
     *
     * @return list<string>
     */
    private function offered(CarbonImmutable $on, int $days = 0): array
    {
        return app(SeasonalTopics::class)
            ->opening(Market::BeNl, $on, $days)
            ->pluck('topic')
            ->all();
    }

    #[Test]
    public function an_out_of_season_topic_is_never_returned(): void
    {
        $this->seedProducts('skibril');
        app(SeasonalTopics::class)->seed(Market::BeNl, CarbonImmutable::create(2027, 6, 1));

        // Wintersport's window is 15 September to 15 February. In June it is
        // stored, visible in admin, and not offered to the calendar.
        $stored = GuideTopic::query()->where('topic', 'wintersport')->first();
        $this->assertNotNull($stored);

        $this->assertNotContains('wintersport', $this->offered(CarbonImmutable::create(2027, 6, 1)));

        // The window is what holds it back, not a shortage of products: the
        // same row is offered once the window has opened.
        $this->assertContains('wintersport', $this->offered(CarbonImmutable::create(2027, 9, 20)));
    }

    #[Test]
    public function a_window_that_wraps_the_year_end_still_opens(): void
    {
        // Valentine's runs 27 December to 14 February. Compared as strings,
        // "01-05" is neither >= "12-27" nor <= ... unless the wrap is handled.
        $this->seedProducts('sieraden');
        $on = CarbonImmutable::create(2027, 1, 5);
        app(SeasonalTopics::class)->seed(Market::BeNl, $on);

        $this->assertContains('valentijnscadeau', $this->offered($on), 'a wrapping window never opened');
    }

    #[Test]
    public function a_seasonal_topic_never_fabricates_a_search_volume(): void
    {
        /*
         * The one number that must stay honest. `search_volume` is the only real
         * demand signal the system has, and admin's "180 searches, 0 products"
         * report is useful exactly as long as every figure in it was measured.
         */
        $this->seedProducts('gasbarbecue');
        app(SeasonalTopics::class)->seed(Market::BeNl, CarbonImmutable::create(2027, 4, 15));

        $this->assertSame(0, GuideTopic::query()->where('topic', 'barbecue')->value('search_volume'));
    }

    #[Test]
    public function seeding_never_overturns_an_editors_decision(): void
    {
        $this->seedProducts('gasbarbecue');
        app(SeasonalTopics::class)->seed(Market::BeNl, CarbonImmutable::create(2027, 4, 15));

        $this->assertContains('barbecue', $this->offered(CarbonImmutable::create(2027, 4, 15)));

        GuideTopic::query()->where('topic', 'barbecue')->update(['status' => 'rejected']);

        // Re-seeding is a nightly job. If it reset the status, a rejected topic
        // would come back every single night.
        app(SeasonalTopics::class)->seed(Market::BeNl, CarbonImmutable::create(2027, 4, 16));

        $this->assertSame('rejected', GuideTopic::query()->where('topic', 'barbecue')->value('status'));
        // And the calendar honours the decision inside the window, too.
        $this->assertNotContains('barbecue', $this->offered(CarbonImmutable::create(2027, 4, 16)));
    }

    #[Test]
    public function it_merges_rather_than_replaces_queries_people_actually_typed(): void
    {
        $this->seedProducts('gasbarbecue');

        GuideTopic::create([
            'market' => Market::BeNl->value,
            'topic' => 'barbecue',
            'origin' => 'search',
            'member_queries' => ['barbecue kopen', 'bbq aanbieding'],
            'search_volume' => 120,
            'available_products' => 40,
            'score' => 50,
            'status' => 'candidate',
        ]);

        app(SeasonalTopics::class)->seed(Market::BeNl, CarbonImmutable::create(2027, 4, 15));

        $queries = GuideTopic::query()->where('topic', 'barbecue')->value('member_queries');

        // A seasonal topic colliding with a mined one is the best outcome — it
        // means real demand exists for a season we already knew was coming — so
        // the mined queries must survive.
        $this->assertContains('barbecue kopen', $queries);
        $this->assertContains('gasbarbecue', $queries);
    }

    #[Test]
    public function a_topic_with_too_few_products_is_stored_but_not_offered(): void
    {
        // Four is below the five-product floor: a "best X" page with four entries
        // reads as thin to a reader and to a crawler.
        $this->seedProducts('gasbarbecue', count: 4);
        app(SeasonalTopics::class)->seed(Market::BeNl, CarbonImmutable::create(2027, 4, 15));

        $this->assertNotNull(GuideTopic::query()->where('topic', 'barbecue')->first());
        $this->assertNotContains('barbecue', $this->offered(CarbonImmutable::create(2027, 4, 15)));
    }

    #[Test]
    public function queries_are_resolved_in_the_market_language(): void
    {
        /*
         * The failure this prevents: left in Dutch, "tent" and "slaapzak" match
         * nothing in a French catalogue, so every seasonal topic in be-fr
         * reported zero products and the feature was silently inert outside the
         * Dutch markets. In admin that reads as "no demand" rather than "wrong
         * words" — the worst kind of failure, because it is plausible.
         */
        $this->seedProducts('tente', market: Market::BeFr);
        app(SeasonalTopics::class)->seed(Market::BeFr, CarbonImmutable::create(2027, 5, 1));

        $queries = GuideTopic::query()
            ->where('market', Market::BeFr->value)
            ->where('topic', 'kamperen')
            ->value('member_queries');

        $this->assertContains('tente', $queries);
        $this->assertNotContains('slaapzak', $queries);
    }

    #[Test]
    public function a_numeric_search_term_does_not_break_the_miner(): void
    {
        /*
         * The first run against a live search log died here: PHP converts a
         * numeric-string array key to an int, so a real search for "4090" or
         * "2024" reaches `availableProducts()` as an integer and fails its string
         * type hint. Every fixture query was a word, so nothing caught it.
         */
        foreach (['4090', '2024', 'koptelefoon'] as $query) {
            SearchLog::create([
                'query' => $query,
                'query_hash' => hash('sha256', $query.'be-nl'),
                'market' => Market::BeNl->value,
                'hour_bucket' => now()->startOfHour(),
                'search_count' => 9,
                'result_count' => 4,
            ]);
        }

        $written = app(TopicMiner::class)->mine(Market::BeNl);

        $this->assertGreaterThan(0, $written);
        $this->assertNotNull(GuideTopic::query()->where('topic', '4090')->first());
    }

    #[Test]
    public function a_regional_topic_stays_in_its_own_markets(): void
    {
        $this->seedProducts('speelgoed');

        app(SeasonalTopics::class)->seed(Market::Es, CarbonImmutable::create(2027, 11, 1));

        // Sinterklaas is not a Spanish event.
        $this->assertNull(
            GuideTopic::query()->where('market', Market::Es->value)->where('topic', 'sinterklaas')->first(),
        );
    }
}
