<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\CoveKind;
use App\Enums\Market;
use App\Enums\PlanWriter;
use App\Jobs\BuildCove;
use App\Jobs\PublishDueCoves;
use App\Jobs\RunEditorialAutomation;
use App\Models\CovePlan;
use App\Services\Settings\AutomationSettingsStore;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The pipeline on the scheduler, behind switches.
 *
 * The same stages an instruction drives. What these pin is the safety: with the
 * shipped grid nothing publishes that would not have published yesterday, and
 * the one switch that removes a person is the one that has to be turned on
 * deliberately.
 */
class EditorialAutomationTest extends TestCase
{
    use RefreshDatabase;

    private AutomationSettingsStore $settings;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2027-04-15')->setTime(12, 0));

        $this->settings = app(AutomationSettingsStore::class);
        $this->settings->flush();
    }

    #[Test]
    public function the_shipped_grid_publishes_nothing_new(): void
    {
        /*
         * Deploy day must change nothing. The seed reproduces what the scheduler
         * already does: the column planned, written and built; everything else
         * buildable but only once a person has approved it.
         */
        foreach (Market::cases() as $market) {
            $this->assertTrue($this->settings->enabled('build', $market, CoveKind::Daily));
            $this->assertTrue($this->settings->enabled('plan', $market, CoveKind::Daily));
            $this->assertSame('builder', $this->settings->writer($market, CoveKind::Daily));

            /*
             * Build is on for every kind, because `PublishDueCoves` already
             * honours an approved plan of any kind on its due date — seeding it
             * off would silently stop every seasonal part publishing.
             */
            $this->assertTrue($this->settings->enabled('build', $market, CoveKind::Seasonal));

            // And the one that removes a person is off everywhere.
            foreach (CoveKind::cases() as $kind) {
                $this->assertFalse(
                    $this->settings->enabled('approve', $market, $kind),
                    "approve ships on for {$kind->value} in {$market->value}",
                );
            }
        }

        $this->assertSame([], $this->settings->publishingMarkets());
    }

    #[Test]
    public function with_everything_off_the_walk_does_nothing(): void
    {
        Queue::fake();

        $this->allOff();

        $plan = $this->plan(['status' => 'approved', 'editorial' => 'Geschreven.']);

        app()->call([new RunEditorialAutomation(Market::BeNl), 'handle']);

        Queue::assertNothingPushed();
        $this->assertSame(0, CovePlan::query()->where('id', '!=', $plan->id)->count());
    }

    #[Test]
    public function the_approve_switch_is_the_only_one_that_removes_a_person(): void
    {
        /*
         * Everything else prepares work. `buildArticle()` refuses a plan nobody
         * approved, so a market with plan, curate, write and build all on still
         * cannot put a page in front of a reader.
         */
        $this->allOff();
        $written = $this->plan(['editorial' => 'Geschreven.', 'writer' => PlanWriter::Authored->value]);

        $this->on('build', CoveKind::Guide);

        app()->call([new RunEditorialAutomation(Market::BeNl), 'handle']);

        $this->assertSame('draft', $written->fresh()->status);

        // Now the switch that changes who decides.
        $this->on('approve', CoveKind::Guide);

        app()->call([new RunEditorialAutomation(Market::BeNl), 'handle']);

        $this->assertSame('approved', $written->fresh()->status);
    }

    #[Test]
    public function a_plan_marked_authored_but_unwritten_is_never_approved(): void
    {
        // That is a plan waiting on a writer, not one waiting on a decision.
        $this->allOff();
        $this->on('approve', CoveKind::Guide);

        $waiting = $this->plan(['writer' => PlanWriter::Authored->value]);

        app()->call([new RunEditorialAutomation(Market::BeNl), 'handle']);

        $this->assertSame('draft', $waiting->fresh()->status);
    }

    #[Test]
    public function the_walk_leaves_dated_plans_to_the_job_that_owns_their_date(): void
    {
        /*
         * Building a seasonal part here would publish it before its window opens
         * and rebuild a Daily outside its own morning — and two builds of one
         * page race over the same edition row. `PublishDueCoves` owns the date.
         */
        Queue::fake();

        $this->allOff();
        $this->on('build', CoveKind::Seasonal);

        $this->plan([
            'kind' => CoveKind::Seasonal->value,
            'status' => 'approved',
            'drop_date' => '2027-05-01',
        ]);

        app()->call([new RunEditorialAutomation(Market::BeNl), 'handle']);

        Queue::assertNotPushed(BuildCove::class);
    }

    #[Test]
    public function switching_build_off_stops_the_due_job_for_that_kind(): void
    {
        // The gate on `PublishDueCoves`, which is not absorbed: it carries the
        // window guard and `built_for`, which belong to seasons rather than to
        // automation.
        Queue::fake();

        $this->allOff();

        $this->plan([
            'kind' => CoveKind::Seasonal->value,
            'status' => 'approved',
            'drop_date' => '2027-04-14',
            'season_from' => '03-01',
            'season_to' => '08-31',
        ]);

        app()->call([new PublishDueCoves(Market::BeNl), 'handle']);
        Queue::assertNotPushed(BuildCove::class);

        $this->on('build', CoveKind::Seasonal);

        app()->call([new PublishDueCoves(Market::BeNl), 'handle']);
        Queue::assertPushed(BuildCove::class);
    }

    #[Test]
    public function the_external_writer_setting_marks_plans_for_the_queue(): void
    {
        /*
         * The whole of `external`: marking a plan `authored` hands it to
         * `GET /coves/queue`, which lists only those — so the built-in writer
         * and an outside agent can never target the same plan.
         */
        $this->allOff();
        $this->settings->putGrid(Market::BeNl, [CoveKind::Guide->value => ['write' => 'external']]);

        $plan = $this->plan();

        app()->call([new RunEditorialAutomation(Market::BeNl), 'handle']);

        $this->assertSame(PlanWriter::Authored, $plan->fresh()->writer);
    }

    #[Test]
    public function a_kind_with_no_source_cannot_be_switched_on(): void
    {
        // Advice has no source a machine can read. The cell is refused rather
        // than offered and always failing.
        $this->assertFalse(AutomationSettingsStore::applies('plan', CoveKind::Advice));
        $this->assertNotNull(AutomationSettingsStore::whyNot('plan', CoveKind::Advice));

        $this->settings->putGrid(Market::BeNl, [CoveKind::Advice->value => ['plan' => '1']]);
        $this->settings->flush();

        $this->assertFalse($this->settings->enabled('plan', Market::BeNl, CoveKind::Advice));
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    private function allOff(): void
    {
        $grid = [];

        foreach (CoveKind::cases() as $kind) {
            foreach (AutomationSettingsStore::STAGES as $stage) {
                $grid[$kind->value][$stage] = $stage === 'write' ? 'off' : '0';
            }
        }

        foreach (Market::cases() as $market) {
            $this->settings->putGrid($market, $grid);
        }

        $this->settings->flush();
    }

    private function on(string $stage, CoveKind $kind): void
    {
        $this->settings->putGrid(Market::BeNl, [$kind->value => [$stage => '1']]);
        $this->settings->flush();
    }

    /** @param array<string, mixed> $attributes */
    private function plan(array $attributes = []): CovePlan
    {
        return CovePlan::create([
            'market' => Market::BeNl->value,
            'kind' => CoveKind::Guide->value,
            'slug' => 'gids-'.bin2hex(random_bytes(4)),
            'title' => 'Een gids',
            'status' => 'draft',
            ...$attributes,
        ]);
    }
}
