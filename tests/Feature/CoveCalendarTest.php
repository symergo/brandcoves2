<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\Availability;
use App\Enums\CoveKind;
use App\Enums\Market;
use App\Enums\ProductStatus;
use App\Enums\Source;
use App\Filament\Pages\CoveCalendar;
use App\Jobs\BuildCove;
use App\Jobs\PublishDueCoves;
use App\Models\CovePlan;
use App\Models\GuideTopic;
use App\Models\Merchant;
use App\Models\Product;
use App\Models\ProductGroup;
use App\Models\User;
use App\Services\Cove\SeasonalSeries;
use App\Services\Cove\YearCalendar;
use App\Services\Guides\SeasonalTopics;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A calendar that comes round every year, and a screen that shows it.
 *
 * The seasonal series shipped able to lay a season out once. That is a calendar
 * for one spring, not a calendar: `kamperen` was planned in 2027 and never
 * offered again, so the pages it produced were never refreshed and a subject the
 * catalogue could not fill that year was never reconsidered.
 *
 * Two properties are pinned here. **A season comes back** — its parts slide onto
 * the coming window and rebuild at the same URLs, because an evergreen page that
 * is republished at a new address every year is three pages competing for one
 * query by the third year. And **the year is legible before anything is planned**
 * — the calendar is assembled from the config rather than from the database, so
 * a fresh environment shows the whole of 2029 on the first page load.
 */
class CoveCalendarTest extends TestCase
{
    use RefreshDatabase;

    private const TODAY = '2027-04-15';

    private Merchant $merchant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse(self::TODAY)->setTime(12, 0));

        $this->merchant = Merchant::create([
            'source' => Source::Awin->value,
            'external_id' => 'shop',
            'name' => 'Shop',
        ]);
    }

    // ── The season comes round ────────────────────────────────────────────

    #[Test]
    public function a_season_that_has_run_is_brought_round_for_the_next_window(): void
    {
        $topic = $this->laidOut();
        $parts = $this->parts();

        $this->assertSame(['2027-04-16', '2027-05-30'], $this->dates($parts));

        // A year on, a fortnight before the window reopens.
        $this->travelTo(CarbonImmutable::parse('2028-03-01')->setTime(3, 0));
        $touched = app(SeasonalSeries::class)->plan($topic->fresh());

        $this->assertCount(2, $touched);

        $renewed = $this->parts();

        // Inside 2028's window, spread the same way.
        $this->assertSame(['2028-03-15', '2028-05-30'], $this->dates($renewed));

        /*
         * And the pages did not move. This is the whole decision: an evergreen
         * page republished at `beste-kamperen-2028-deel-1` would leave last
         * year's competing with it for the same query, and the ranking the
         * seasonal window exists to buy indexing time for is what would be split.
         */
        foreach ($renewed as $index => $part) {
            $this->assertSame($parts[$index]->slug, $part->slug, 'a part changed its URL');
            $this->assertSame($parts[$index]->id, $part->id);
            $this->assertSame($parts[$index]->title, $part->title);
        }
    }

    #[Test]
    public function bringing_a_season_round_is_what_makes_its_pages_rebuild(): void
    {
        Queue::fake();

        $topic = $this->laidOut();
        CovePlan::query()->update(['status' => 'approved']);

        // Built for its 2027 date, and then not again — a page must not rewrite
        // itself nightly for as long as its plan exists.
        $first = $this->parts()[0];
        $first->forceFill(['edition_id' => null, 'built_for' => $first->drop_date])->save();

        $this->travelTo(CarbonImmutable::parse('2027-06-01')->setTime(7, 0));
        app()->call([new PublishDueCoves(Market::BeNl), 'handle']);

        Queue::assertNotPushed(BuildCove::class, fn (BuildCove $job) => $job->planId === $first->id);

        // Next spring the calendar moves it, and that is the only thing that
        // does: no status is rewound and nothing is cleared.
        $this->travelTo(CarbonImmutable::parse('2028-03-01')->setTime(3, 0));
        app(SeasonalSeries::class)->plan($topic->fresh());

        $this->assertSame('approved', $first->fresh()->status);

        $this->travelTo(CarbonImmutable::parse('2028-03-16')->setTime(7, 0));
        app()->call([new PublishDueCoves(Market::BeNl), 'handle']);

        Queue::assertPushed(BuildCove::class, fn (BuildCove $job) => $job->planId === $first->id);
    }

    #[Test]
    public function a_season_already_scheduled_for_its_next_window_is_left_alone(): void
    {
        $topic = $this->laidOut();
        $before = $this->dates($this->parts());

        // The weekly planner run, over and over. Idempotence is what stops it
        // re-dating a series every Monday and dragging published pages around.
        $this->assertSame([], app(SeasonalSeries::class)->plan($topic->fresh()));
        $this->assertSame([], app(SeasonalSeries::class)->plan($topic->fresh()));

        $this->assertSame($before, $this->dates($this->parts()));
    }

    #[Test]
    public function a_subject_the_catalogue_could_not_fill_last_year_joins_the_series(): void
    {
        // Two subjects fillable, one not: three brands against a floor of five.
        $this->shelf('tent');
        $this->shelf('slaapzak');
        $this->shelf('campingstoel', count: 3);

        $topic = $this->topic(['tent', 'slaapzak', 'campingstoel']);
        app(SeasonalSeries::class)->lay($topic);

        $this->assertCount(2, $this->parts());

        // An advertiser arrives over the winter.
        $this->shelf('campingstoel', count: 6, offset: 3);

        $this->travelTo(CarbonImmutable::parse('2028-03-01')->setTime(3, 0));
        app(SeasonalSeries::class)->plan($topic->fresh());

        $parts = $this->parts();

        $this->assertCount(3, $parts);
        $this->assertSame(3, $parts[2]->part);
        $this->assertSame('campingstoel', $parts[2]->focus_keyphrase);
        $this->assertSame('beste-kamperen-deel-3', $parts[2]->slug);

        // Drafted, like everything else the planner writes: nobody has read it.
        $this->assertSame('draft', $parts[2]->status);

        // Dated after the parts that already exist, and inside the window. The
        // series that has run keeps its slots — a newcomer does not get to move
        // published pages' schedules to make room for itself.
        $this->assertGreaterThan($parts[1]->drop_date->toDateString(), $parts[2]->drop_date->toDateString());
        $this->assertLessThanOrEqual('2028-08-15', $parts[2]->drop_date->toDateString());
    }

    #[Test]
    public function a_season_that_was_one_page_stays_one_page(): void
    {
        $this->shelf('tent');

        $topic = $this->topic(['tent', 'slaapzak']);
        app(SeasonalSeries::class)->lay($topic);

        $single = CovePlan::query()->where('kind', CoveKind::Seasonal->value)->sole();
        $this->assertSame('beste-kamperen', $single->slug);

        // The second subject becomes fillable.
        $this->shelf('slaapzak');

        $this->travelTo(CarbonImmutable::parse('2028-03-01')->setTime(3, 0));
        app(SeasonalSeries::class)->plan($topic->fresh());

        /*
         * Still one page, and still at its unnumbered address. Promoting it
         * would either rename a live URL or leave a series whose first part is
         * addressed unlike the rest; it refreshes as a single page instead.
         */
        $this->assertSame(1, CovePlan::query()->where('kind', CoveKind::Seasonal->value)->count());
        $this->assertSame('beste-kamperen', $single->fresh()->slug);
        $this->assertNull($single->fresh()->part);

        // Re-dated, though: the season itself comes round.
        $this->assertSame('2028-03-15', $single->fresh()->drop_date->toDateString());
    }

    #[Test]
    public function a_rejected_season_is_never_brought_round(): void
    {
        $this->shelf('tent');
        $this->shelf('slaapzak');
        $this->topic(['tent', 'slaapzak'])->update(['status' => 'rejected']);

        // A person turned it down. A yearly pass that overturned that would
        // bring it back every spring for ever.
        $offered = app(SeasonalTopics::class)
            ->opening(Market::BeNl, CarbonImmutable::parse(self::TODAY), 30);

        $this->assertTrue($offered->where('topic', 'kamperen')->isEmpty());
    }

    #[Test]
    public function the_planner_command_brings_a_season_round(): void
    {
        $this->shelf('gasbarbecue');
        $this->shelf('houtskoolbarbecue');

        $this->artisan('bc:plan-coves', ['--market' => 'be-nl', '--days' => 1, '--no-products' => true])
            ->assertSuccessful();

        $before = CovePlan::query()->where('series_key', 'barbecue')->orderBy('part')->pluck('drop_date');
        $this->assertCount(2, $before);

        /*
         * A year on, inside the window again. `--days 1` looks one day ahead,
         * so this has to be a date the season is actually open on — which is
         * the same gate the weekly run applies, at a cadence that reaches every
         * window it opens.
         */
        $this->travelTo(CarbonImmutable::parse('2028-04-01')->setTime(3, 0));

        $this->artisan('bc:plan-coves', ['--market' => 'be-nl', '--days' => 1, '--no-products' => true])
            ->assertSuccessful();

        $after = CovePlan::query()->where('series_key', 'barbecue')->orderBy('part')->pluck('drop_date');

        $this->assertCount(2, $after, 'the season was laid out a second time instead of renewed');
        $this->assertSame('2028', $after[0]->format('Y'));
    }

    // ── The year view ─────────────────────────────────────────────────────

    #[Test]
    public function the_year_is_drawn_before_anything_has_been_seeded(): void
    {
        $months = app(YearCalendar::class)->for(Market::BeNl, 2029);

        $this->assertCount(12, $months);

        /*
         * Two years out, on a database with no topics and no plans. The calendar
         * is assembled from the config rather than from rows, which is what
         * makes it a recurring calendar rather than a report on the rows that
         * happen to exist.
         */
        $summary = app(YearCalendar::class)->summary(Market::BeNl, 2029);

        $this->assertGreaterThan(0, $summary['seasons']);
        $this->assertGreaterThan(0, $summary['days']);
        $this->assertSame(0, $summary['seasonsPlanned']);
        $this->assertSame(0, $summary['daysPlanned']);
    }

    #[Test]
    public function a_season_appears_in_every_month_it_runs_through(): void
    {
        $months = app(YearCalendar::class)->for(Market::BeNl, 2027);
        $inMonth = fn (int $month) => array_column($months[$month - 1]['seasons'], 'topic');

        // Camping runs 15 March to 15 August. A season listed only where it
        // starts would hide that half of August is three overlapping windows.
        $this->assertContains('kamperen', $inMonth(3));
        $this->assertContains('kamperen', $inMonth(6));
        $this->assertContains('kamperen', $inMonth(8));
        $this->assertNotContains('kamperen', $inMonth(9));
    }

    #[Test]
    public function a_regional_season_stays_out_of_the_markets_it_means_nothing_in(): void
    {
        $dutch = app(YearCalendar::class)->for(Market::BeNl, 2027);
        $spanish = app(YearCalendar::class)->for(Market::Es, 2027);

        $topics = fn (array $months) => array_merge(...array_map(
            fn (array $m) => array_column($m['seasons'], 'topic'),
            $months,
        ));

        $this->assertContains('sinterklaas', $topics($dutch));
        $this->assertNotContains('sinterklaas', $topics($spanish));
    }

    #[Test]
    public function a_named_day_shows_whether_it_has_been_planned(): void
    {
        CovePlan::create([
            'market' => Market::BeNl->value,
            'kind' => CoveKind::Daily->value,
            'drop_date' => '2027-02-14',
            'title' => 'Valentijn',
            'status' => 'approved',
        ]);

        $february = app(YearCalendar::class)->for(Market::BeNl, 2027)[1];
        $days = collect($february['days'])->keyBy('date');

        $this->assertNotNull($days->get('2027-02-14')['plan']);
        $this->assertSame('approved', $days->get('2027-02-14')['plan']['status']);

        // Every other named day in the month is honestly unplanned.
        $this->assertNull($days->except(['2027-02-14'])->first()['plan'] ?? null);
    }

    #[Test]
    public function the_calendar_screen_renders_and_can_draft_a_day(): void
    {
        $this->shelf('tent');

        Livewire::actingAs($this->admin())
            ->test(CoveCalendar::class)
            ->assertSuccessful()
            ->set('market', Market::BeNl->value)
            ->set('year', 2027)
            ->assertSuccessful()
            ->call('planDay', '2027-12-25')
            ->assertSuccessful();

        $plan = CovePlan::query()
            ->where('kind', CoveKind::Daily->value)
            ->whereDate('drop_date', '2027-12-25')
            ->first();

        $this->assertNotNull($plan);

        // A draft, always. A calendar button that published would be a content
        // farm with a nicer interface.
        $this->assertSame('draft', $plan->status);
    }

    #[Test]
    public function the_calendar_screen_lays_a_season_out(): void
    {
        $this->shelf('tent');
        $this->shelf('slaapzak');

        Livewire::actingAs($this->admin())
            ->test(CoveCalendar::class)
            ->set('market', Market::BeNl->value)
            ->call('planSeason', 'kamperen')
            ->assertSuccessful();

        $parts = $this->parts();

        $this->assertCount(2, $parts);
        $this->assertSame('draft', $parts[0]->status);
    }

    // ── Fixtures ──────────────────────────────────────────────────────────

    /** A camping season already laid out as two parts. */
    private function laidOut(): GuideTopic
    {
        $this->shelf('tent');
        $this->shelf('slaapzak');

        $topic = $this->topic(['tent', 'slaapzak']);
        app(SeasonalSeries::class)->lay($topic);

        return $topic;
    }

    /** @return list<CovePlan> */
    private function parts(): array
    {
        return CovePlan::query()
            ->where('kind', CoveKind::Seasonal->value)
            ->orderByRaw('part nulls first')
            ->orderBy('id')
            ->get()
            ->values()
            ->all();
    }

    /**
     * @param  list<CovePlan>  $parts
     * @return list<string>
     */
    private function dates(array $parts): array
    {
        return array_map(fn (CovePlan $p) => $p->drop_date->toDateString(), $parts);
    }

    /** @param list<string> $queries */
    private function topic(array $queries): GuideTopic
    {
        return GuideTopic::create([
            'market' => Market::BeNl->value,
            'topic' => 'kamperen',
            'origin' => 'seasonal',
            'member_queries' => $queries,
            'search_volume' => 0,
            'available_products' => 40,
            'score' => 10,
            'status' => 'candidate',
            'season_from' => '03-15',
            'season_to' => '08-15',
        ]);
    }

    private function admin(): User
    {
        return User::factory()->create(['is_admin' => true]);
    }

    private function shelf(string $noun, int $count = 6, int $offset = 0): void
    {
        $brands = ['Vaude', 'Deuter', 'Nomad', 'Quechua', 'Weber', 'Coleman', 'Robens', 'Wolfskin',
            'Bardani', 'Eurotrail', 'Outwell', 'Salewa'];

        foreach (array_slice($brands, $offset, $count) as $index => $brand) {
            $this->product("{$brand} {$noun} model {$index}", 4000 + ($index * 1500), $brand.'-'.$noun);
        }
    }

    private function product(string $title, int $price, string $brand): ProductGroup
    {
        $group = ProductGroup::create([
            'market' => Market::BeNl,
            'identity_key' => 'k'.bin2hex(random_bytes(5)),
            'identity_kind' => 'ean',
            'title' => $title,
            'slug' => 'p-'.bin2hex(random_bytes(4)),
            'brand' => $brand,
            'category' => 'outdoor',
            'image_url' => 'https://img.test/x.jpg',
            'min_price' => $price,
            'merchant_count' => 2,
            'in_stock' => true,
            'giftable' => true,
            'worth_showing' => true,
            'surprise_score' => 50,
        ]);

        Product::create([
            'source' => Source::Awin,
            'market' => Market::BeNl,
            'merchant_id' => $this->merchant->id,
            'group_id' => $group->id,
            'external_id' => 'e'.bin2hex(random_bytes(5)),
            'title' => $title,
            'price' => $price,
            'currency' => 'EUR',
            'affiliate_url' => 'https://example.test/buy',
            'availability' => Availability::InStock,
            'status' => ProductStatus::Active,
            'identity_key' => $group->identity_key,
        ]);

        return $group;
    }
}
