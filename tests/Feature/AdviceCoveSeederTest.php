<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\CoveKind;
use App\Enums\Market;
use App\Enums\PublishStatus;
use App\Models\CovePlan;
use App\Models\DailyPickSet;
use App\Services\Content\AdviceCoveSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The shipped advice articles, and the one property that matters: **running
 * this again must never cost somebody their edit.**
 *
 * The migration calls the same service, so everything asserted here is
 * asserted about the deploy.
 */
class AdviceCoveSeederTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Start from nothing, whatever the environment did.
     *
     * The seeding migration skips the test environment on purpose — see the
     * note in `2026_09_03_000100_the_advice_coves_move_in` — so in practice
     * this removes nothing. It stays because the assertions below are of the
     * form "seeding created this row", and that is only a claim about the
     * seeder if the row provably was not there first. If the migration's guard
     * were ever dropped, these tests would quietly start asserting about the
     * fixture instead, and would keep passing while doing it.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $ids = DailyPickSet::query()->where('kind', CoveKind::Advice->value)->pluck('id');

        CovePlan::query()->whereIn('edition_id', $ids)->delete();
        DailyPickSet::query()->whereIn('id', $ids)->delete();
    }

    private function runSeeder(bool $dryRun = false, bool $replace = false, ?Market $only = null): array
    {
        return app(AdviceCoveSeeder::class)->run($dryRun, $replace, $only);
    }

    #[Test]
    public function it_publishes_the_shipped_articles_as_advice_coves(): void
    {
        $this->runSeeder();

        $coves = DailyPickSet::query()->where('kind', CoveKind::Advice->value)->get();

        $this->assertGreaterThan(0, $coves->count());

        foreach ($coves as $cove) {
            $this->assertSame(PublishStatus::Published, $cove->status);
            $this->assertNotNull($cove->published_at);
            // The CHECK constraint allows a date only on a Daily, and a
            // dateless kind that acquired one gets published as that morning's
            // column.
            $this->assertNull($cove->drop_date);
            $this->assertNotEmpty($cove->body);
        }
    }

    #[Test]
    public function every_seeded_cove_gets_a_plan_so_it_can_be_recurated(): void
    {
        $this->runSeeder();

        $cove = DailyPickSet::query()->where('kind', CoveKind::Advice->value)->firstOrFail();
        $plan = CovePlan::query()->where('edition_id', $cove->id)->first();

        $this->assertNotNull($plan);
        // Minted as a record, never as an instruction the next build obeys.
        $this->assertSame('used', $plan->status);
    }

    #[Test]
    public function running_it_twice_changes_nothing(): void
    {
        $this->runSeeder();

        $before = DailyPickSet::query()->where('kind', CoveKind::Advice->value)->count();
        $plansBefore = CovePlan::query()->count();

        $second = $this->runSeeder();

        $this->assertSame($before, DailyPickSet::query()->where('kind', CoveKind::Advice->value)->count());
        // recordFor() re-links rather than minting a second plan per Cove.
        $this->assertSame($plansBefore, CovePlan::query()->count());
        // The rows are still ours, so a re-run rewrites rather than skipping.
        $this->assertNotEmpty($second['written']);
    }

    #[Test]
    public function published_at_is_stamped_once_and_never_moves(): void
    {
        /*
         * It orders the shelf. Refreshing it on a re-run would re-date every
         * advice article to the top of "newest first" each time the file is
         * touched.
         */
        $this->runSeeder();

        $cove = DailyPickSet::query()->where('kind', CoveKind::Advice->value)->firstOrFail();
        $stamped = $cove->published_at;

        $this->travel(2)->days();
        $this->runSeeder();

        $this->assertTrue($stamped->equalTo($cove->fresh()->published_at));
    }

    #[Test]
    public function an_edited_cove_is_kept_not_overwritten(): void
    {
        /*
         * THE TEST THIS FILE EXISTS FOR.
         *
         * The marker is the only thing standing between a re-run and somebody's
         * rewrite. A deploy runs the migration; if this property does not hold,
         * every deploy silently reverts the editorial team's work.
         */
        $this->runSeeder();

        $cove = DailyPickSet::query()->where('kind', CoveKind::Advice->value)->firstOrFail();

        $cove->update([
            'body' => 'A human wrote this instead.',
            'editorial_source' => 'human',
        ]);

        $report = $this->runSeeder();

        $this->assertSame('A human wrote this instead.', $cove->fresh()->body);
        $this->assertNotEmpty($report['kept']);
    }

    #[Test]
    public function replace_overwrites_an_edited_cove_deliberately(): void
    {
        $this->runSeeder();

        $cove = DailyPickSet::query()->where('kind', CoveKind::Advice->value)->firstOrFail();
        $cove->update(['body' => 'Human copy.', 'editorial_source' => 'human']);

        $this->runSeeder(replace: true);

        $this->assertNotSame('Human copy.', $cove->fresh()->body);
        $this->assertSame(AdviceCoveSeeder::SOURCE, $cove->fresh()->editorial_source);
    }

    #[Test]
    public function a_slug_already_taken_by_another_kind_is_skipped_rather_than_stolen(): void
    {
        /*
         * The unique index on (market, slug) spans every kind, so this would
         * otherwise surface as a database error halfway through a deploy —
         * and the row it collided with is not ours to overwrite.
         */
        $content = require resource_path('content/advice-coves.php');
        $first = reset($content);

        DailyPickSet::query()->create([
            'market' => Market::BeNl->value,
            'kind' => CoveKind::Persona->value,
            'slug' => $first['be-nl']['slug'],
            'theme_title' => 'A persona that got here first',
            'theme_slug' => 'persona-first',
            'status' => PublishStatus::Published->value,
            'published_at' => now(),
            'drop_date' => null,
        ]);

        $report = $this->runSeeder(only: Market::BeNl);

        $this->assertNotEmpty($report['skipped']);
        $this->assertStringContainsString('slug taken', implode(' ', $report['skipped']));

        // And the persona is untouched.
        $this->assertSame(
            CoveKind::Persona,
            DailyPickSet::query()->where('slug', $first['be-nl']['slug'])->firstOrFail()->kind,
        );
    }

    #[Test]
    public function a_dry_run_writes_nothing(): void
    {
        $report = $this->runSeeder(dryRun: true);

        $this->assertNotEmpty($report['written']);
        $this->assertSame(0, DailyPickSet::query()->where('kind', CoveKind::Advice->value)->count());
    }

    #[Test]
    public function it_can_be_limited_to_one_market(): void
    {
        $this->runSeeder(only: Market::BeNl);

        $markets = DailyPickSet::query()
            ->where('kind', CoveKind::Advice->value)
            ->pluck('market')
            // Cast to the enum on the way out of the model.
            ->map(fn (Market $m) => $m->value)
            ->unique();

        $this->assertSame([Market::BeNl->value], $markets->values()->all());
    }
}
