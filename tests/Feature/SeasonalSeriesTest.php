<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\Availability;
use App\Enums\CoveKind;
use App\Enums\Market;
use App\Enums\ProductStatus;
use App\Enums\PublishStatus;
use App\Enums\Source;
use App\Jobs\BuildCove;
use App\Jobs\PublishDueCoves;
use App\Models\CovePlan;
use App\Models\DailyPickSet;
use App\Models\GuideTopic;
use App\Models\Merchant;
use App\Models\Product;
use App\Models\ProductGroup;
use App\Services\Cove\SeasonalSeries;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A season, published as a dated series rather than as one page.
 *
 * A seasonal topic used to become a single guide, written whenever the queue
 * reached it, carrying a three-month window on its own — and it was invisible in
 * the one place editorial is actually decided, because the Cove planner held
 * Dailies and a pile of undated ideas.
 *
 * These tests pin the four properties that make the change worth having rather
 * than merely present: a season is split on the subjects it already names, a
 * subject the catalogue cannot fill never becomes a page, the parts land inside
 * the window and in the future, and a part goes live on the day an editor
 * scheduled it for rather than the day they approved it.
 */
class SeasonalSeriesTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Mid-April 2027.
     *
     * Inside `kamperen`'s window (15 March to 15 August) and far enough into it
     * that the first part's natural slot is already past — which is the case the
     * date arithmetic gets wrong if it is written the obvious way.
     */
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

    // ── Laying a season out ───────────────────────────────────────────────

    #[Test]
    public function a_season_becomes_one_part_per_subject_it_names(): void
    {
        $this->shelf('tent');
        $this->shelf('slaapzak');

        $parts = app(SeasonalSeries::class)->lay($this->topic(['tent', 'slaapzak']));

        $this->assertCount(2, $parts);

        foreach ($parts as $index => $part) {
            $this->assertSame(CoveKind::Seasonal, $part->kind);
            $this->assertSame('kamperen', $part->series_key);
            $this->assertSame($index + 1, $part->part);
            $this->assertSame('draft', $part->status);
        }

        // Numbered in the market's language, in the title and in the address.
        // "part" in a Dutch URL reads as a page somebody forgot to finish.
        $this->assertSame('Kamperen, deel 1', $parts[0]->title);
        $this->assertSame('beste-kamperen-deel-1', $parts[0]->slug);
        $this->assertSame('Kamperen, deel 2', $parts[1]->title);
        $this->assertSame('beste-kamperen-deel-2', $parts[1]->slug);

        // The facet, not the season: it is the first term the ladder retrieves
        // on and the whole reason part two is about something else.
        $this->assertSame('tent', $parts[0]->focus_keyphrase);
        $this->assertSame('slaapzak', $parts[1]->focus_keyphrase);
    }

    #[Test]
    public function no_product_appears_on_two_parts_of_one_season(): void
    {
        // One shelf, two subjects that both retrieve from it — the shape that
        // makes a "series" two copies of one page if nothing is carried forward.
        $this->shelf('tent', count: 12);

        $parts = app(SeasonalSeries::class)->lay($this->topic(['tent', 'tent']));

        // The duplicate facet is folded away before anything is written; two
        // parts about the identical phrase is not a series either.
        $this->assertCount(1, $parts);

        $this->shelf('slaapzak', count: 8);

        GuideTopic::query()->delete();
        CovePlan::query()->delete();

        $parts = app(SeasonalSeries::class)->lay($this->topic(['tent', 'slaapzak']));

        $first = $parts[0]->items()->pluck('group_id')->all();
        $second = $parts[1]->items()->pluck('group_id')->all();

        $this->assertNotEmpty($first);
        $this->assertNotEmpty($second);
        $this->assertSame([], array_intersect($first, $second));
    }

    #[Test]
    public function a_subject_the_catalogue_cannot_fill_never_becomes_a_part(): void
    {
        $this->shelf('tent');
        // Three brands, against a floor of five. Enough to look like a subject
        // and not enough to be a buying guide.
        $this->shelf('slaapzak', count: 3);

        $parts = app(SeasonalSeries::class)->lay($this->topic(['tent', 'slaapzak']));

        $this->assertCount(1, $parts);

        /*
         * And one part is not a series. A page titled "part 1" with no part two
         * is a promise to a reader that nothing keeps, so the numbering is
         * dropped from the title, the URL and the columns alike.
         */
        $this->assertSame('Kamperen', $parts[0]->title);
        $this->assertSame('beste-kamperen', $parts[0]->slug);
        $this->assertNull($parts[0]->part);
        $this->assertNull($parts[0]->series_key);
    }

    #[Test]
    public function a_season_the_catalogue_cannot_fill_at_all_stays_in_the_queue(): void
    {
        $topic = $this->topic(['tent', 'slaapzak']);

        $this->assertSame([], app(SeasonalSeries::class)->lay($topic));

        // Parked, not banned: a category that is thin in April may have an
        // advertiser in May, and marking it queued would mean never noticing.
        $topic->refresh();
        $this->assertNull($topic->plan_id);
        $this->assertSame('candidate', $topic->status);
        $this->assertSame(0, CovePlan::query()->count());
    }

    // ── The dates ─────────────────────────────────────────────────────────

    #[Test]
    public function the_parts_are_spread_across_the_window_and_never_land_in_the_past(): void
    {
        $this->shelf('tent');
        $this->shelf('slaapzak');

        $parts = app(SeasonalSeries::class)->lay($this->topic(['tent', 'slaapzak']));

        $dates = array_map(fn (CovePlan $p) => $p->drop_date->toDateString(), $parts);

        /*
         * Part one's natural slot is 15 March, five weeks ago. It is late, not
         * cancelled, so it queues from tomorrow — and part two keeps the slot
         * the window gave it rather than being dragged forward with it.
         */
        $this->assertSame('2027-04-16', $dates[0]);
        $this->assertSame('2027-05-30', $dates[1]);

        // Inside the window it was written for, at both ends. A part dated after
        // 15 August is a page published once the demand has gone.
        foreach ($dates as $date) {
            $this->assertGreaterThanOrEqual('2027-03-15', $date);
            $this->assertLessThanOrEqual('2027-08-15', $date);
        }
    }

    #[Test]
    public function a_dated_part_does_not_cost_the_day_its_daily_cove(): void
    {
        $this->shelf('tent');
        $this->shelf('slaapzak');

        $parts = app(SeasonalSeries::class)->lay($this->topic(['tent', 'slaapzak']));
        $taken = $parts[0]->drop_date->toDateString();

        /*
         * The unique index used to be "one dated plan per market per day", which
         * would make this insert fail. The rule it was written for is about the
         * Daily *address* — only one edition can be reached at /daily/{date} —
         * and a seasonal part is reached by its slug. Its date is when the work
         * is due, not where the page is.
         */
        $daily = CovePlan::create([
            'market' => Market::BeNl->value,
            'kind' => CoveKind::Daily->value,
            'drop_date' => $taken,
            'title' => 'Rond de tafel',
            'status' => 'draft',
        ]);

        $this->assertSame(
            2,
            CovePlan::query()->whereDate('drop_date', $taken)->count(),
            'the season took the day away from the Daily',
        );

        // And a second *Daily* for that day is still refused.
        $this->expectException(QueryException::class);

        CovePlan::create([
            'market' => $daily->market->value,
            'kind' => CoveKind::Daily->value,
            'drop_date' => $taken,
            'title' => 'Iets anders',
            'status' => 'draft',
        ]);
    }

    // ── Publishing on the day it was scheduled for ────────────────────────

    #[Test]
    public function an_approved_part_is_built_on_its_due_date_and_not_before(): void
    {
        Queue::fake();

        $this->shelf('tent');
        $this->shelf('slaapzak');

        $parts = app(SeasonalSeries::class)->lay($this->topic(['tent', 'slaapzak']));

        foreach ($parts as $part) {
            $part->update(['status' => 'approved']);
        }

        // Part one is due tomorrow and part two at the end of May.
        app()->call([new PublishDueCoves(Market::BeNl), 'handle']);

        Queue::assertNothingPushed();

        $this->travelTo(CarbonImmutable::parse('2027-04-16')->setTime(7, 0));
        app()->call([new PublishDueCoves(Market::BeNl), 'handle']);

        Queue::assertPushed(BuildCove::class, fn (BuildCove $job) => $job->planId === $parts[0]->id);
        Queue::assertPushed(BuildCove::class, 1);
    }

    #[Test]
    public function a_draft_part_is_never_published_by_the_clock(): void
    {
        Queue::fake();

        $this->shelf('tent');
        $this->shelf('slaapzak');

        app(SeasonalSeries::class)->lay($this->topic(['tent', 'slaapzak']));

        /*
         * The whole approval gate in one assertion. A date on a plan says when
         * an approved page goes live; it is not a second way to approve one, or
         * the planner would be a publishing pipeline with a calendar bolted on.
         */
        $this->travelTo(CarbonImmutable::parse('2027-06-01')->setTime(7, 0));
        app()->call([new PublishDueCoves(Market::BeNl), 'handle']);

        Queue::assertNothingPushed();
    }

    #[Test]
    public function a_part_whose_window_has_closed_waits_rather_than_publishing_late(): void
    {
        Queue::fake();

        $this->shelf('tent');
        $this->shelf('slaapzak');

        $parts = app(SeasonalSeries::class)->lay($this->topic(['tent', 'slaapzak']));
        CovePlan::query()->update(['status' => 'approved']);

        /*
         * November, three months after the camping window shut. The part is
         * approved and overdue and must still not appear: the window is when the
         * demand exists, and a camping guide published in November is worse than
         * a missing one. It keeps its approval for next year.
         */
        $this->travelTo(CarbonImmutable::parse('2027-11-01')->setTime(7, 0));
        app()->call([new PublishDueCoves(Market::BeNl), 'handle']);

        Queue::assertNothingPushed();
        $this->assertSame('approved', $parts[0]->fresh()->status);
    }

    // ── What the reader sees ──────────────────────────────────────────────

    #[Test]
    public function a_published_part_links_to_the_rest_of_its_series(): void
    {
        $one = $this->publishedPart('kamperen', 1, 'Kamperen, deel 1', 'beste-kamperen-deel-1');
        $this->publishedPart('kamperen', 2, 'Kamperen, deel 2', 'beste-kamperen-deel-2');

        $this->get('/be-nl/guides/'.$one->slug)
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('rail.series.0.title', 'Kamperen, deel 1')
                ->where('rail.series.0.current', true)
                ->where('rail.series.1.title', 'Kamperen, deel 2')
                ->where('rail.series.1.current', false)
                // Ordered by part rather than by publication: the reader is
                // being offered a reading order, not a feed.
                ->where('rail.series.1.url', '/be-nl/guides/beste-kamperen-deel-2'));
    }

    #[Test]
    public function one_published_part_is_a_page_rather_than_a_series(): void
    {
        // Part two is written but not live yet. A heading over a list of one
        // reads as a block whose contents failed to load.
        $one = $this->publishedPart('kamperen', 1, 'Kamperen, deel 1', 'beste-kamperen-deel-1');
        $this->publishedPart('kamperen', 2, 'Kamperen, deel 2', 'beste-kamperen-deel-2', PublishStatus::Draft);

        $this->get('/be-nl/guides/'.$one->slug)
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('rail.series', null));
    }

    // ── The planner command ───────────────────────────────────────────────

    #[Test]
    public function the_planner_command_lays_the_open_seasons_out(): void
    {
        // April: the barbecue window (15 March to 31 July) is open, and its
        // first noun is what a Belgian catalogue would actually carry.
        $this->shelf('gasbarbecue');
        $this->shelf('houtskoolbarbecue');

        $this->artisan('bc:plan-coves', ['--market' => 'be-nl', '--days' => 1, '--no-products' => true])
            ->assertSuccessful();

        $laid = CovePlan::query()
            ->where('kind', CoveKind::Seasonal->value)
            ->where('series_key', 'barbecue')
            ->orderBy('part')
            ->get();

        $this->assertCount(2, $laid);

        // Dated, which is the entire point: before this the calendar could show
        // a season's schedule nowhere at all.
        foreach ($laid as $part) {
            $this->assertNotNull($part->drop_date);
            $this->assertSame('draft', $part->status);
        }
    }

    // ── Fixtures ──────────────────────────────────────────────────────────

    /**
     * A seasonal topic, as `SeasonalTopics::seed()` would have written it.
     *
     * `kamperen`'s real window, so the date arithmetic is asserted against the
     * calendar the site actually ships rather than against a convenient one.
     *
     * @param  list<string>  $queries
     */
    private function topic(array $queries, string $topic = 'kamperen'): GuideTopic
    {
        return GuideTopic::create([
            'market' => Market::BeNl->value,
            'topic' => $topic,
            'origin' => 'seasonal',
            'member_queries' => $queries,
            // Never fabricated. A young site has measured no searches for a
            // season it has not lived through yet, and that zero is honest.
            'search_volume' => 0,
            'available_products' => 40,
            'score' => 10,
            'status' => 'candidate',
            'season_from' => '03-15',
            'season_to' => '08-15',
        ]);
    }

    /** A published part of a series, plan and edition together. */
    private function publishedPart(
        string $series,
        int $part,
        string $title,
        string $slug,
        PublishStatus $status = PublishStatus::Published,
    ): DailyPickSet {
        $edition = DailyPickSet::create([
            'market' => Market::BeNl,
            'kind' => CoveKind::Seasonal->value,
            'drop_date' => null,
            'slug' => $slug,
            'theme_title' => $title,
            'theme_blurb' => 'Waar dit over gaat.',
            'theme_slug' => $slug,
            'status' => $status->value,
            'published_at' => $status === PublishStatus::Published ? now()->subHour() : null,
        ]);

        CovePlan::create([
            'market' => Market::BeNl->value,
            'kind' => CoveKind::Seasonal->value,
            'title' => $title,
            'slug' => $slug,
            'series_key' => $series,
            'part' => $part,
            'status' => 'used',
            'edition_id' => $edition->id,
        ]);

        return $edition;
    }

    /**
     * A shelf of one product noun, one brand each.
     *
     * One per brand because that is how the ladder shortlists: six rows of one
     * brand yield one product, and the part would be dropped as unfillable for
     * a reason that has nothing to do with what is being tested.
     */
    private function shelf(string $noun, int $count = 6): void
    {
        $brands = ['Vaude', 'Deuter', 'Nomad', 'Quechua', 'Weber', 'Coleman', 'Robens', 'Jack Wolfskin',
            'Bardani', 'Eurotrail', 'Outwell', 'Salewa'];

        foreach (array_slice($brands, 0, $count) as $index => $brand) {
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
