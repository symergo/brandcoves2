<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\Market;
use App\Models\GuideTopic;
use App\Models\ProductGroup;
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
 * These tests pin the two things that make the fix work rather than merely exist:
 * the window opens *before* the season, and an in-season topic beats a
 * higher-scoring evergreen one.
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
    private function seedProducts(string $noun, int $count = 6): void
    {
        for ($i = 0; $i < $count; $i++) {
            ProductGroup::create([
                'market' => Market::BeNl->value,
                'identity_key' => "seasonal-{$noun}-{$i}",
                'identity_kind' => 'title',
                'title' => "Merk {$noun} model {$i}",
                'slug' => "{$noun}-{$i}",
                'brand' => 'Merk',
                'image_url' => "https://example.test/{$noun}-{$i}.jpg",
                'min_price' => 4900,
                'max_price' => 8900,
                'median_price' => 8900,
                'offer_count' => 1,
                'merchant_count' => 1,
                'in_stock' => true,
            ]);
        }
    }

    #[Test]
    public function a_seasonal_topic_is_in_season_before_its_season_starts(): void
    {
        $this->seedProducts('gasbarbecue');

        // Mid-April. Nobody has searched for a barbecue yet and the shops are
        // already selling them — which is the whole point of the window.
        $topic = app(SeasonalTopics::class)->ripest(Market::BeNl, CarbonImmutable::create(2027, 4, 15));

        // seed() has to run first; ripest() reads rows, it does not create them.
        $this->assertNull($topic, 'ripest() must not invent rows');

        app(SeasonalTopics::class)->seed(Market::BeNl, CarbonImmutable::create(2027, 4, 15));

        $topic = app(SeasonalTopics::class)->ripest(Market::BeNl, CarbonImmutable::create(2027, 4, 15));

        $this->assertNotNull($topic);
        $this->assertSame('seasonal', $topic->origin);
    }

    #[Test]
    public function an_out_of_season_topic_is_never_returned(): void
    {
        $this->seedProducts('skibril');
        app(SeasonalTopics::class)->seed(Market::BeNl, CarbonImmutable::create(2027, 6, 1));

        // Wintersport's window is 15 September to 15 February. In June it is
        // stored, visible in admin, and not offered to the builder.
        $stored = GuideTopic::query()->where('topic', 'wintersport')->first();
        $this->assertNotNull($stored);

        $ripest = app(SeasonalTopics::class)->ripest(Market::BeNl, CarbonImmutable::create(2027, 6, 1));
        $this->assertNotSame('wintersport', $ripest?->topic);
    }

    #[Test]
    public function a_window_that_wraps_the_year_end_still_opens(): void
    {
        // Valentine's runs 27 December to 14 February. Compared as strings,
        // "01-05" is neither >= "12-27" nor <= ... unless the wrap is handled.
        $this->seedProducts('sieraden');
        app(SeasonalTopics::class)->seed(Market::BeNl, CarbonImmutable::create(2027, 1, 5));

        $ripest = app(SeasonalTopics::class)->ripest(Market::BeNl, CarbonImmutable::create(2027, 1, 5));

        $this->assertNotNull($ripest, 'a wrapping window never opened');
    }

    #[Test]
    public function the_soonest_closing_window_goes_first(): void
    {
        // Ordered by urgency rather than size: a Cove written three weeks before
        // its season is nearly worthless, and one written three months before is
        // an asset for a decade.
        $this->seedProducts('halloween verkleedkleding');
        $this->seedProducts('luchtreiniger');

        $on = CarbonImmutable::create(2027, 10, 20);
        app(SeasonalTopics::class)->seed(Market::BeNl, $on);

        // Halloween closes on 31 October; air quality runs to 31 December.
        $this->assertSame('halloween', app(SeasonalTopics::class)->ripest(Market::BeNl, $on)?->topic);
    }

    #[Test]
    public function an_in_season_topic_beats_a_higher_scoring_evergreen_one(): void
    {
        $this->seedProducts('gasbarbecue');

        // An evergreen topic with far more measured demand.
        GuideTopic::create([
            'market' => Market::BeNl->value,
            'topic' => 'koptelefoon',
            'origin' => 'search',
            'member_queries' => ['koptelefoon'],
            'search_volume' => 5000,
            'available_products' => 400,
            'score' => 9999,
            'status' => 'candidate',
        ]);

        $this->travelTo(CarbonImmutable::create(2027, 4, 15));
        app(SeasonalTopics::class)->seed(Market::BeNl);

        $this->assertSame('barbecue', app(TopicMiner::class)->ripest(Market::BeNl)?->topic);
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

        GuideTopic::query()->where('topic', 'barbecue')->update(['status' => 'rejected']);

        // Re-seeding is a nightly job. If it reset the status, a rejected topic
        // would come back every single night.
        app(SeasonalTopics::class)->seed(Market::BeNl, CarbonImmutable::create(2027, 4, 16));

        $this->assertSame('rejected', GuideTopic::query()->where('topic', 'barbecue')->value('status'));
        $this->assertNotSame('barbecue', app(SeasonalTopics::class)->ripest(Market::BeNl, CarbonImmutable::create(2027, 4, 16))?->topic);
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
        $this->assertNotSame('barbecue', app(SeasonalTopics::class)->ripest(Market::BeNl, CarbonImmutable::create(2027, 4, 15))?->topic);
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
