<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\CoveKind;
use App\Enums\Market;
use App\Enums\PublishStatus;
use App\Models\CovePlan;
use App\Models\DailyPickSet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Every published Cove has a plan behind it, including the ones nobody planned.
 *
 * Until now the planner could only describe the future: a Daily assembled at
 * 06:00 and a guide published by the topic queue left no plan, so most of what
 * is live had no record of what it was for and could not be re-curated.
 *
 * The minted record has to be inert. A plan is also an *instruction* — an
 * approved one outranks the theme calendar and its shortlist leads the edition —
 * so a record of what a machine already did must never be mistaken for a
 * decision a person made.
 */
class CoveRecordedPlanTest extends TestCase
{
    use RefreshDatabase;

    private function edition(array $attributes = []): DailyPickSet
    {
        return DailyPickSet::create(array_merge([
            'market' => Market::BeNl->value,
            'kind' => CoveKind::Daily->value,
            'drop_date' => today()->toDateString(),
            'theme_title' => 'Dinsdagvondsten',
            'theme_blurb' => 'Zeven dingen.',
            'theme_slug' => 'dinsdagvondsten',
            'theme_source' => 'ai',
            'status' => PublishStatus::Published->value,
            'published_at' => now(),
        ], $attributes));
    }

    #[Test]
    public function an_unplanned_edition_gets_a_plan_that_records_it(): void
    {
        $edition = $this->edition();

        $plan = CovePlan::recordFor($edition);

        $this->assertSame($edition->id, $plan->edition_id);
        $this->assertSame(CoveKind::Daily, $plan->kind);
        $this->assertSame('Dinsdagvondsten', $plan->title);
        $this->assertSame($edition->drop_date->toDateString(), $plan->drop_date->toDateString());
    }

    #[Test]
    public function a_recorded_plan_is_used_and_never_drives_the_next_build(): void
    {
        $edition = $this->edition();

        $plan = CovePlan::recordFor($edition);

        $this->assertSame('used', $plan->status);

        /*
         * The whole point. `approvedFor()` is what decides whether a plan
         * *drives* a build; if a minted record answered it, the machine's own
         * output would become an editorial instruction the next rebuild obeys —
         * pinning tomorrow's theme to today's.
         */
        $this->assertNull(CovePlan::approvedFor(Market::BeNl, today()->toImmutable()));
    }

    #[Test]
    public function recording_the_same_edition_twice_does_not_mint_a_second_plan(): void
    {
        $edition = $this->edition();

        $first = CovePlan::recordFor($edition);
        $second = CovePlan::recordFor($edition);

        // Rebuilding is routine — a scheduler retry, a redeploy, an editor
        // pressing the button — so this runs far more often than once.
        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, CovePlan::query()->count());
    }

    #[Test]
    public function an_authors_approved_plan_is_linked_but_never_demoted(): void
    {
        $plan = CovePlan::create([
            'market' => Market::BeNl->value,
            'kind' => CoveKind::Daily->value,
            'drop_date' => today()->toDateString(),
            'title' => 'Moederdag',
            'status' => 'approved',
        ]);

        $edition = $this->edition();

        CovePlan::recordFor($edition);

        $plan->refresh();

        /*
         * Marking it `used` is the obvious move and it is a bug: the next
         * rebuild of this date would no longer find the plan, and would quietly
         * replace the author's title and prose with generated ones.
         */
        $this->assertSame('approved', $plan->status);
        $this->assertSame('Moederdag', $plan->title);
        $this->assertSame($edition->id, $plan->edition_id);
        $this->assertSame(1, CovePlan::query()->count());
    }

    #[Test]
    public function a_recorded_plan_claims_no_curation_that_never_happened(): void
    {
        $edition = $this->edition();

        $plan = CovePlan::recordFor($edition);

        /*
         * A curated item leads the edition and is exempt from the ninety-day
         * repeat memory. Seeding the shortlist from what the ranker published
         * would convert an automatic Cove into a curated one, and the next
         * routine rebuild would republish exactly the products it was meant to
         * refresh.
         */
        $this->assertSame(0, $plan->items()->count());
    }

    #[Test]
    public function a_dateless_cove_is_recorded_against_its_slug(): void
    {
        $edition = $this->edition([
            'kind' => CoveKind::Persona->value,
            'drop_date' => null,
            'slug' => 'de-cottagecore-kruidenvrouw',
            'theme_title' => 'De cottagecore-kruidenvrouw',
        ]);

        $plan = CovePlan::recordFor($edition);

        // Four of the five kinds are dateless; the address is the slug, and the
        // partial unique index it upserts against is (market, slug).
        $this->assertSame('de-cottagecore-kruidenvrouw', $plan->slug);
        $this->assertNull($plan->drop_date);
        $this->assertSame($edition->id, CovePlan::recordFor($edition->fresh())->edition_id);
        $this->assertSame(1, CovePlan::query()->count());
    }
}
