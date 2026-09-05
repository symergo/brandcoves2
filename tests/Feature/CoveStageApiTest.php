<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\CoveKind;
use App\Enums\Market;
use App\Enums\PlanWriter;
use App\Jobs\BuildCove;
use App\Models\ApiToken;
use App\Models\CovePlan;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * One stage, over a set.
 *
 * A run to `build` costs about four writes per Cove and writes are throttled to
 * 20/min per token, so a thirty-Cove push spent most of an hour being paced.
 * The alternative — a looser limit for publish-capable keys — weakens the
 * retry-loop protection for exactly the keys that can reach a reader.
 */
class CoveStageApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2027-04-15')->setTime(12, 0));
    }

    #[Test]
    public function a_run_reports_per_plan_and_never_a_bare_count(): void
    {
        /*
         * The property that makes a batch endpoint trustworthy. A run that says
         * "3 approved" when one of them was skipped is the failure this exists
         * to prevent, and it is the one an unattended run cannot notice for
         * itself.
         */
        $ready = $this->plan(fn ($p) => $p->update([
            'editorial' => 'Geschreven.',
            'writer' => PlanWriter::Authored->value,
        ]));
        $already = $this->plan(fn ($p) => $p->update(['status' => 'approved']));

        $response = $this->withToken($this->key(publish: true))
            ->postJson('/api/editorial/coves/stages/approve', [
                'ids' => [$ready->id, $already->id],
            ])
            ->assertOk();

        $outcomes = collect($response->json('data'))->keyBy('id');

        $this->assertSame('approved', $outcomes[$ready->id]['outcome']);
        $this->assertSame('skipped', $outcomes[$already->id]['outcome']);
        $this->assertSame('Already approved.', $outcomes[$already->id]['why']);

        // Each entry says where it came from and where it got to, so a caller
        // can see the run's effect without re-reading every plan.
        $this->assertSame('written', $outcomes[$ready->id]['from']);
        $this->assertSame('approved', $outcomes[$ready->id]['to']);
    }

    #[Test]
    public function a_dry_run_changes_nothing_and_says_what_it_would_do(): void
    {
        // "Show me what this would publish" has to be answerable before the
        // publishing, not after it.
        $plan = $this->plan(fn ($p) => $p->update([
            'editorial' => 'Geschreven.',
            'writer' => PlanWriter::Authored->value,
        ]));

        $this->withToken($this->key(publish: true))
            ->postJson('/api/editorial/coves/stages/approve', ['ids' => [$plan->id], 'dryRun' => true])
            ->assertOk()
            ->assertJsonPath('data.0.outcome', 'would_approve');

        $this->assertSame('draft', $plan->fresh()->status);
    }

    #[Test]
    public function a_selector_picks_the_set_the_planner_would_show(): void
    {
        /*
         * "Build and publish the coves for which the products are picked" is a
         * selector plus a target stage. The selector is `PlanState`, which the
         * planner screen reads too, so the set acted on is the set displayed.
         */
        $written = $this->plan(fn ($p) => $p->update([
            'editorial' => 'Geschreven.',
            'writer' => PlanWriter::Authored->value,
        ]));
        $this->plan();

        $response = $this->withToken($this->key(publish: true))
            ->postJson('/api/editorial/coves/stages/approve', [
                'market' => Market::BeNl->value,
                'state' => 'written',
            ])
            ->assertOk();

        $this->assertSame(1, $response->json('count'));
        $this->assertSame($written->id, $response->json('data.0.id'));
    }

    #[Test]
    public function building_queues_rather_than_claiming_it_published(): void
    {
        /*
         * Queued is not built. The thin-catalogue decision happens inside the
         * job, minutes later, so the honest answer points at the read-back
         * rather than reporting success.
         */
        Queue::fake();

        $plan = $this->plan(fn ($p) => $p->update(['status' => 'approved']));

        $response = $this->withToken($this->key(publish: true))
            ->postJson('/api/editorial/coves/stages/build', ['ids' => [$plan->id]])
            ->assertOk()
            ->assertJsonPath('data.0.outcome', 'queued');

        $this->assertStringContainsString('thin', (string) $response->json('data.0.why'));

        Queue::assertPushed(BuildCove::class);
    }

    #[Test]
    public function a_writing_key_may_curate_but_not_approve_or_build(): void
    {
        // Curating a draft cannot reach a reader; approving and building can.
        // Checked before any work is done, so a refused run changes nothing.
        $plan = $this->plan(fn ($p) => $p->update(['status' => 'approved']));

        foreach (['approve', 'build'] as $stage) {
            $this->withToken($this->key(publish: false))
                ->postJson("/api/editorial/coves/stages/{$stage}", ['ids' => [$plan->id]])
                ->assertStatus(403);
        }

        $this->withToken($this->key(publish: false))
            ->postJson('/api/editorial/coves/stages/curate', ['ids' => [$plan->id]])
            ->assertOk();
    }

    #[Test]
    public function the_write_stage_is_refused_with_the_reason(): void
    {
        /*
         * The prose comes from the caller, so there is nothing to batch — and an
         * agent told "unknown stage" would guess again, where one told where the
         * words go moves on.
         */
        $this->withToken($this->key(publish: true))
            ->postJson('/api/editorial/coves/stages/write', ['ids' => [$this->plan()->id]])
            ->assertStatus(422)
            ->assertJsonValidationErrors('stage');
    }

    #[Test]
    public function an_empty_selector_is_an_answer_rather_than_a_zero(): void
    {
        // A caller told "0" with no explanation retries forever.
        $response = $this->withToken($this->key(publish: true))
            ->postJson('/api/editorial/coves/stages/approve', ['state' => 'written'])
            ->assertOk();

        $this->assertSame(0, $response->json('count'));
        $this->assertNotEmpty($response->json('message'));
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    private function key(bool $publish): string
    {
        $abilities = [ApiToken::READ, ApiToken::WRITE];

        if ($publish) {
            $abilities[] = ApiToken::PUBLISH;
        }

        return ApiToken::issue('stage key', $abilities)['token'];
    }

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
}
