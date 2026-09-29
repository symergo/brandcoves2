<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\CoveKind;
use App\Enums\CoveScene;
use App\Enums\Market;
use App\Jobs\PlanPersonasFromDemand;
use App\Models\CovePlan;
use App\Models\DailyPickSet;
use App\Models\GiftLanding;
use App\Services\Cove\PersonaDemandPlanner;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Concerns\MakesGiftShelf;
use Tests\TestCase;

/**
 * Gift personas drafted from what people search for (owner's request 6,
 * docs/features/persona-demand.md): counted readings, thresholds, no
 * duplicates with a persona or a landing page, drafts only, no AI.
 */
class PersonaDemandTest extends TestCase
{
    use MakesGiftShelf;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::today()->setTime(12, 0));
    }

    #[Test]
    public function a_gift_search_is_counted_as_its_reading_and_nothing_else(): void
    {
        $this->giftable('Yogamat van kurk', 3900, ['interest:yoga']);

        $this->get('/be-nl/search?q='.urlencode('cadeau voor mijn zus die van yoga houdt'))->assertOk();
        $this->get('/be-nl/search?q='.urlencode('cadeau voor mijn zus die van yoga houdt'))->assertOk();

        $row = DB::table('gift_search_demand')->sole();

        // The reading and a count: no words, no budget, nobody.
        $this->assertSame('be-nl', $row->market);
        $this->assertSame('sibling', $row->relationship);
        $this->assertSame('yoga', $row->interest);
        $this->assertSame(2, (int) $row->searches);

        // A crawler following a chip link is not demand, and a product
        // search is not a gift reading.
        $this->withHeader('User-Agent', 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)')
            ->get('/be-nl/search?q='.urlencode('cadeau voor mijn zus die van yoga houdt'))->assertOk();
        $this->get('/be-nl/search?q=yogamat')->assertOk();

        $this->assertSame(2, (int) DB::table('gift_search_demand')->sum('searches'));
    }

    #[Test]
    public function repeated_demand_becomes_a_draft_persona_and_is_never_published(): void
    {
        $this->forbidAi();
        $this->yogaShelf();
        $this->searched('sibling', 'yoga', searches: 6, days: 3);

        $result = app(PersonaDemandPlanner::class)->plan(Market::BeNl);

        $this->assertCount(1, $result['drafted']);

        $plan = CovePlan::query()->where('kind', CoveKind::Persona->value)->sole();

        $this->assertSame('draft', $plan->status);
        $this->assertEquals(['relationship' => 'sibling', 'interests' => ['yoga']], $plan->brief);
        $this->assertStringStartsWith(PersonaDemandPlanner::MARK, (string) $plan->note);
        // A template title in the market's words, not a model's.
        $this->assertSame('Je broer of zus die van yoga houdt', $plan->title);
        // Pre-filled with the owner's minimum of eight, all answering yoga.
        $this->assertSame(8, $plan->items()->count());

        // Drafted is all it is: no page was built.
        $this->assertSame(0, DailyPickSet::query()->count());
        $this->assertNull($plan->edition_id);
    }

    #[Test]
    public function too_few_searches_or_all_on_one_day_draft_nothing(): void
    {
        $this->yogaShelf();

        // Enough searches, one afternoon: one person, or one crawler.
        $this->searched('sibling', 'yoga', searches: 9, days: 1);
        // Enough days, too few searches.
        $this->searched('mother', 'yoga', searches: 4, days: 4);

        $result = app(PersonaDemandPlanner::class)->plan(Market::BeNl);

        $this->assertSame([], $result['drafted']);
        $this->assertSame(2, $result['skipped']['below_bar']);
        $this->assertSame(0, CovePlan::query()->count());
    }

    #[Test]
    public function a_reading_a_landing_page_answers_is_not_drafted(): void
    {
        $this->yogaShelf();
        $this->searched('sibling', 'yoga', searches: 6, days: 3);

        GiftLanding::create([
            'market' => Market::BeNl->value,
            'recipient' => 'sibling',
            'interest' => 'yoga',
            'path' => '/be-nl/gift-ideas/for/zus/yoga',
            'brief' => ['relationship' => 'sibling', 'interests' => ['yoga']],
            'product_count' => 12,
            'checked_at' => now(),
        ]);

        $result = app(PersonaDemandPlanner::class)->plan(Market::BeNl);

        $this->assertSame([], $result['drafted']);
        $this->assertSame(1, $result['skipped']['landing_page']);
    }

    #[Test]
    public function a_reading_a_persona_answers_is_not_drafted_twice(): void
    {
        $this->yogaShelf();
        $this->searched(null, 'yoga', searches: 7, days: 4);

        app(PersonaDemandPlanner::class)->plan(Market::BeNl);
        app(PersonaDemandPlanner::class)->plan(Market::BeNl);

        // The second night finds its own draft and leaves it alone.
        $this->assertSame(1, CovePlan::query()->count());

        // A rejected draft still answers: rejecting is what stops it coming back.
        CovePlan::query()->update(['status' => 'rejected']);
        $this->assertSame([], app(PersonaDemandPlanner::class)->plan(Market::BeNl)['drafted']);
    }

    #[Test]
    public function a_hand_written_persona_about_the_interest_answers_it(): void
    {
        $this->yogaShelf();
        $this->searched(null, 'yoga', searches: 7, days: 4);
        $this->searched(null, null, searches: 7, days: 4, hasEverything: true);

        // No brief, chosen by search terms: its title names yoga.
        CovePlan::create([
            'market' => Market::BeNl->value, 'kind' => 'persona', 'slug' => 'de-yogaliefhebber',
            'title' => 'De yogaliefhebber', 'status' => 'approved', 'queries' => ['yogamat'],
        ]);
        // And one drawn as "has everything".
        CovePlan::create([
            'market' => Market::BeNl->value, 'kind' => 'persona', 'slug' => 'wie-alles-al-heeft',
            'title' => 'Wie alles al heeft', 'status' => 'approved', 'queries' => ['proeverij'],
            'scene' => CoveScene::HasEverything->value,
        ]);

        $result = app(PersonaDemandPlanner::class)->plan(Market::BeNl);

        $this->assertSame([], $result['drafted']);
        $this->assertSame(2, $result['skipped']['persona']);
    }

    #[Test]
    public function a_reading_the_catalogue_cannot_fill_is_not_drafted(): void
    {
        // Seven yoga products: one short of the owner's minimum.
        foreach (range(1, 7) as $i) {
            $this->giftable("Yogablok {$i}", 1500 + $i * 100, ['interest:yoga']);
        }

        $this->searched('sibling', 'yoga', searches: 6, days: 3);

        $result = app(PersonaDemandPlanner::class)->plan(Market::BeNl);

        $this->assertSame([], $result['drafted']);
        $this->assertSame(1, $result['skipped']['too_few_products']);
    }

    #[Test]
    public function the_search_log_counts_too_read_through_the_same_parser(): void
    {
        $this->yogaShelf();

        // Gift searches logged before the search box learned to read them.
        foreach (range(0, 2) as $daysAgo) {
            $this->logged('yoga cadeau zus', searches: 2, daysAgo: $daysAgo);
        }
        // An ordinary product search is not a reading.
        $this->logged('yogamat', searches: 50, daysAgo: 0);

        $result = app(PersonaDemandPlanner::class)->plan(Market::BeNl, dryRun: true);

        $this->assertCount(1, $result['drafted']);
        $this->assertSame(6, $result['drafted'][0]['searches']);
        $this->assertSame(3, $result['drafted'][0]['days']);
        // A dry run writes nothing.
        $this->assertSame(0, CovePlan::query()->count());
    }

    #[Test]
    public function the_nightly_job_drafts_and_the_market_is_kept_apart(): void
    {
        $this->forbidAi();
        $this->yogaShelf();
        $this->searched('sibling', 'yoga', searches: 6, days: 3);
        // Demand in another market is that market's.
        $this->searched('sibling', 'yoga', searches: 60, days: 30, market: Market::NlNl);

        PlanPersonasFromDemand::dispatchSync(Market::BeNl);

        $this->assertSame(['be-nl'], CovePlan::query()->pluck('market')->map->value->all());
    }

    #[Test]
    public function the_command_is_a_dry_run_unless_asked_to_write(): void
    {
        $this->yogaShelf();
        $this->searched('sibling', 'yoga', searches: 6, days: 3);

        $this->artisan('bc:plan-demand-personas', ['--market' => 'be-nl'])->assertSuccessful();
        $this->assertSame(0, CovePlan::query()->count());

        $this->artisan('bc:plan-demand-personas', ['--market' => 'be-nl', '--write' => true])->assertSuccessful();
        $this->assertSame(1, CovePlan::query()->where('status', 'draft')->count());
    }

    private function yogaShelf(): void
    {
        foreach (range(1, 10) as $i) {
            $this->giftable("Yogamat model {$i}", 1500 + $i * 250, ['interest:yoga']);
        }
    }

    private function searched(?string $relationship, ?string $interest, int $searches, int $days, bool $hasEverything = false, Market $market = Market::BeNl): void
    {
        $left = $searches;

        for ($d = 0; $d < $days; $d++) {
            $count = $d === $days - 1 ? $left : intdiv($searches, $days);
            $left -= $count;

            DB::table('gift_search_demand')->insert([
                'market' => $market->value,
                'day' => now()->subDays($d)->toDateString(),
                'relationship' => (string) $relationship,
                'interest' => (string) $interest,
                'has_everything' => $hasEverything,
                'searches' => $count,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    private function logged(string $query, int $searches, int $daysAgo): void
    {
        DB::table('search_log')->insert([
            'query' => $query,
            'query_hash' => hash('sha256', $query.'|be-nl|'.$daysAgo),
            'market' => 'be-nl',
            'hour_bucket' => now()->subDays($daysAgo)->startOfHour(),
            'search_count' => $searches,
            'result_count' => 3,
            'zero_result_count' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
