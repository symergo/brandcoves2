<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\CoveKind;
use App\Enums\Market;
use App\Enums\PublishStatus;
use App\Models\ApiToken;
use App\Models\CovePlan;
use App\Models\DailyPickSet;
use App\Services\Cove\PlanState;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * One state vocabulary, read by the screen, the API and the selector.
 *
 * `cove_plans.status` has four values and answers neither of the questions an
 * editor actually has: has this been written yet, and did the build work. The
 * second is the important one — an approved plan whose catalogue went thin was
 * indistinguishable from one whose date had not arrived, on every screen.
 *
 * The critical property here is that **`of()` and `scope()` agree**. One is PHP
 * over a loaded model and the other is SQL over a table; they are two
 * implementations of one definition, and if they drift the API silently skips
 * work the panel is showing.
 */
class PlanStateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2027-04-15')->setTime(12, 0));
    }

    #[Test]
    public function every_state_resolves_the_same_way_in_php_and_in_sql(): void
    {
        /*
         * The test that keeps the vocabulary honest. `PlanState::of()` walks a
         * model and `PlanState::scope()` walks the table, and the planner reads
         * one while the API reads the other.
         */
        $plans = [
            PlanState::Draft->value => $this->plan(),
            PlanState::Written->value => $this->plan(fn (CovePlan $p) => $p->update(['editorial' => 'Al geschreven.'])),
            PlanState::Approved->value => $this->plan(fn (CovePlan $p) => $p->update(['status' => 'approved'])),
            PlanState::Thin->value => $this->plan(fn (CovePlan $p) => $p->update([
                'status' => 'approved',
                'last_build_failed_at' => now(),
                'last_build_note' => '3 of the 5 products this kind needs.',
            ])),
            PlanState::Live->value => $this->plan(fn (CovePlan $p) => $p->update([
                'status' => 'approved',
                'edition_id' => $this->edition()->id,
            ])),
            PlanState::Archive->value => $this->plan(fn (CovePlan $p) => $p->update(['status' => 'rejected'])),
        ];

        foreach ($plans as $expected => $plan) {
            $state = PlanState::from($expected);

            $this->assertSame(
                $state,
                PlanState::of($plan->fresh()),
                "of() disagreed for {$state->value}",
            );

            $found = CovePlan::query()->tap(fn ($q) => PlanState::scope($q, $state))->pluck('id')->all();

            $this->assertContains($plan->id, $found, "scope() did not find the {$state->value} plan");

            // And no other plan in the fixture is in this bucket.
            foreach ($plans as $otherExpected => $other) {
                if ($otherExpected !== $expected) {
                    $this->assertNotContains($other->id, $found, "scope({$state->value}) also matched {$otherExpected}");
                }
            }
        }
    }

    #[Test]
    public function a_season_part_redated_into_the_coming_window_is_due_again(): void
    {
        /*
         * The state a whole season sits in between somebody sliding its parts
         * onto next year's window and the day each part is honoured. Without it
         * that work is invisible on every screen in between — the plan is
         * approved, has an edition, and looks finished.
         */
        $plan = CovePlan::create([
            'market' => Market::BeNl->value,
            'kind' => CoveKind::Seasonal->value,
            'slug' => 'kamperen-deel-1',
            'title' => 'Kamperen, deel 1',
            'status' => 'approved',
            'edition_id' => $this->edition()->id,
            'built_for' => '2027-03-16',
            'drop_date' => '2028-03-16',
        ]);

        $this->assertSame(PlanState::DueAgain, PlanState::of($plan));
        $this->assertSame('build', PlanState::of($plan)->nextStage());

        $found = CovePlan::query()->tap(fn ($q) => PlanState::scope($q, PlanState::DueAgain))->pluck('id')->all();
        $this->assertSame([$plan->id], $found);

        // And an unmoved part is not: `built_for` equals `drop_date`, which is
        // exactly the test `PublishDueCoves` makes.
        $plan->update(['built_for' => '2028-03-16']);
        $this->assertSame(PlanState::Live, PlanState::of($plan->fresh()));
    }

    #[Test]
    public function the_api_filters_on_the_same_vocabulary(): void
    {
        $written = $this->plan(fn (CovePlan $p) => $p->update(['editorial' => 'Al geschreven.']));
        $this->plan();

        $response = $this->withToken(ApiToken::issue('reader', [ApiToken::READ])['token'])
            ->getJson('/api/editorial/coves?state=written')
            ->assertOk();

        $this->assertSame(1, $response->json('count'));
        $this->assertSame($written->id, $response->json('data.0.id'));
    }

    #[Test]
    public function a_thin_build_says_why_rather_than_looking_unbuilt(): void
    {
        /*
         * The gap this whole phase exists to close. The builder's refusal is
         * correct — a three-item "best of" is a list with gaps — and it used to
         * be a log line at six in the morning.
         */
        $plan = $this->plan(fn (CovePlan $p) => $p->update([
            'status' => 'approved',
            'last_build_failed_at' => now(),
            'last_build_note' => '3 of the 5 products this kind needs. The catalogue could not fill it.',
        ]));

        $response = $this->withToken(ApiToken::issue('reader', [ApiToken::READ])['token'])
            ->getJson("/api/editorial/coves/{$plan->id}/edition")
            ->assertOk();

        $this->assertSame('thin', $response->json('data.state'));
        // And it names what to do about it, which "not built" never could.
        $this->assertSame('curate', $response->json('data.nextStage'));
        $this->assertStringContainsString('3 of the 5', (string) $response->json('data.lastBuild.why'));
        $this->assertNull($response->json('data.edition'));
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    private function plan(?callable $tweak = null): CovePlan
    {
        $plan = CovePlan::create([
            'market' => Market::BeNl->value,
            'kind' => CoveKind::Guide->value,
            'slug' => 'gids-'.bin2hex(random_bytes(4)),
            'title' => 'Een gids',
            'status' => 'draft',
        ]);

        if ($tweak !== null) {
            $tweak($plan);
        }

        return $plan->fresh();
    }

    private function edition(): DailyPickSet
    {
        return DailyPickSet::create([
            'market' => Market::BeNl->value,
            'kind' => CoveKind::Guide->value,
            'slug' => 'editie-'.bin2hex(random_bytes(4)),
            'theme_title' => 'Een gids',
            'theme_slug' => 'een-gids',
            'status' => PublishStatus::Published->value,
            'published_at' => now(),
        ]);
    }
}
