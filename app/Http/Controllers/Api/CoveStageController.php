<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Enums\CoveKind;
use App\Enums\Market;
use App\Http\Controllers\Controller;
use App\Http\Middleware\AuthenticateApiToken;
use App\Jobs\BuildCove;
use App\Jobs\BuildDailyEdition;
use App\Models\ApiToken;
use App\Models\CovePlan;
use App\Services\Cove\EditionBuilder;
use App\Services\Cove\PlanState;
use App\Services\Curation\PlanCurator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * One stage, run over many plans.
 *
 * A run to `build` costs roughly four writes per Cove and the editorial API
 * throttles writes to 20/min per token, so a thirty-Cove seasonal push spent
 * most of an hour being paced. The alternative was a looser limit for
 * publish-capable keys, which weakens the retry-loop protection for exactly the
 * keys that can reach a reader. Fewer, larger calls is the better trade.
 *
 * ## The grammar this serves
 *
 * An instruction is a **selector** plus a **target stage**, and naming a stage
 * implies every earlier one not already satisfied. "Build and publish the coves
 * for which the products are picked" is `state=draft` → `approve` → `build`.
 * The selector is `PlanState`, which the planner screen reads too, so the set
 * this acts on is the set the panel is showing.
 *
 * ## Per-id, never a bare count
 *
 * Every response says what happened to each plan. A run that reports "24 built"
 * when three of them skipped on a thin catalogue is the failure this whole
 * surface exists to prevent — and it is the one an unattended run cannot notice
 * for itself.
 */
class CoveStageController extends Controller
{
    /** Anything larger is a scheduled job's work, not a request's. */
    private const MAX = 50;

    public function __construct(
        private readonly PlanCurator $curator,
        private readonly EditionBuilder $builder,
    ) {}

    public function run(Request $request, string $stage): JsonResponse
    {
        $data = $request->validate([
            'ids' => ['nullable', 'array', 'max:'.self::MAX],
            'ids.*' => ['integer'],
            'market' => ['nullable', Rule::in(Market::values())],
            'kinds' => ['nullable', 'array'],
            'kinds.*' => [Rule::in(CoveKind::values())],
            'state' => ['nullable', Rule::in(PlanState::values())],
            'limit' => ['nullable', 'integer', 'min:1', 'max:'.self::MAX],
            'dryRun' => ['nullable', 'boolean'],
        ]);

        $stage = $this->stage($stage);
        $this->assertMayRun($request, $stage);

        $plans = $this->select($data);

        if ($plans->isEmpty()) {
            return response()->json([
                'stage' => $stage,
                'count' => 0,
                // Not an error. An empty selector is the ordinary end of a run,
                // and a caller told "0" with no explanation retries forever.
                'message' => 'Nothing matched that selector. Narrow it with GET /coves?state= first.',
                'data' => [],
            ]);
        }

        $dryRun = $request->boolean('dryRun');

        $results = $plans->map(fn (CovePlan $plan) => $this->apply($stage, $plan, $dryRun))->all();

        return response()->json([
            'stage' => $stage,
            'dryRun' => $dryRun,
            'count' => count($results),
            /*
             * A tally beside the detail, because the detail is what matters and
             * the tally is what gets read first.
             */
            'summary' => collect($results)->countBy('outcome')->all(),
            'data' => $results,
        ]);
    }

    /**
     * Do one stage to one plan, and say what happened.
     *
     * @return array<string, mixed>
     */
    private function apply(string $stage, CovePlan $plan, bool $dryRun): array
    {
        $from = PlanState::of($plan);

        $result = match ($stage) {
            'curate' => $this->curate($plan, $dryRun),
            'approve' => $this->approve($plan, $dryRun),
            'build' => $this->build($plan, $dryRun),
        };

        return [
            'id' => $plan->id,
            'market' => $plan->market->value,
            'kind' => $plan->kind->value,
            'title' => $plan->title,
            'from' => $from->value,
            'to' => PlanState::of($plan->fresh())->value,
            ...$result,
        ];
    }

    /** @return array<string, mixed> */
    private function curate(CovePlan $plan, bool $dryRun): array
    {
        $target = $plan->kind->targetItems();
        $want = max(0, $target - $plan->items()->count());

        if ($target === 0) {
            return ['outcome' => 'skipped', 'why' => 'This kind carries no products; its substance is the writing.'];
        }

        if ($want < 1) {
            return ['outcome' => 'skipped', 'why' => "Already carries {$target} products."];
        }

        if ($dryRun) {
            return ['outcome' => 'would_curate', 'why' => "Would look for {$want} more product(s)."];
        }

        $added = 0;

        foreach ($this->builder->candidates($plan, $want) as $group) {
            $this->curator->add($plan, 'group:'.$group->id);
            $added++;
        }

        return [
            'outcome' => $added > 0 ? 'curated' : 'skipped',
            'added' => $added,
            'why' => $added < $want
                ? "The catalogue offered {$added} of the {$want} still needed."
                : null,
        ];
    }

    /** @return array<string, mixed> */
    private function approve(CovePlan $plan, bool $dryRun): array
    {
        if ($plan->status === 'used') {
            return ['outcome' => 'skipped', 'why' => 'Already used by an edition.'];
        }

        if ($plan->status === 'approved') {
            // Idempotent by design: a fragment must be safe to re-run, because
            // that is how "run it again from where it got to" works at all.
            return ['outcome' => 'skipped', 'why' => 'Already approved.'];
        }

        /*
         * Approving a plan with no prose is legal and usually not meant.
         *
         * The builder will write it, which is a real workflow — but in a batch
         * run reaching `approve` it almost always means the writing stage was
         * skipped, so it is reported rather than silently done.
         */
        $unwritten = blank($plan->editorial) && blank($plan->body) && ! $plan->writer->callsModel();

        if ($unwritten) {
            return [
                'outcome' => 'skipped',
                'why' => 'Marked as written by hand, but carries no prose. Write it, or set writer=builder.',
            ];
        }

        if ($dryRun) {
            return ['outcome' => 'would_approve'];
        }

        $plan->update(['status' => 'approved']);

        return ['outcome' => 'approved'];
    }

    /** @return array<string, mixed> */
    private function build(CovePlan $plan, bool $dryRun): array
    {
        if ($plan->status !== 'approved') {
            return ['outcome' => 'skipped', 'why' => "Only an approved plan is built. This one is '{$plan->status}'."];
        }

        $addressed = $plan->kind->isDated() ? $plan->drop_date !== null : filled($plan->slug);

        if (! $addressed) {
            return ['outcome' => 'skipped', 'why' => 'No address to build at.'];
        }

        if ($dryRun) {
            return ['outcome' => 'would_build'];
        }

        /*
         * Queued, never inline. The build selects products and may call a model,
         * and neither belongs in the seconds an HTTP client will wait —
         * invariant 1 besides.
         */
        $plan->kind->isDated()
            ? BuildDailyEdition::dispatch($plan->market, $plan->drop_date->toDateString())
            : BuildCove::dispatch($plan->id);

        return [
            'outcome' => 'queued',
            /*
             * Queued is not built. A thin catalogue is decided inside the job,
             * so the honest read-back is the plan's own state afterwards — which
             * is why `Thin` exists and why this points at it.
             */
            'why' => 'Dispatched. Read GET /coves/'.$plan->id.' afterwards: state `live` if it published, '
                .'`thin` if the catalogue could not fill it.',
        ];
    }

    /**
     * The plans this run acts on.
     *
     * @param  array<string, mixed>  $data
     * @return Collection<int, CovePlan>
     */
    private function select(array $data): Collection
    {
        if (($data['ids'] ?? []) !== []) {
            // Explicit ids win and are not filtered further: a caller naming
            // them has already decided, and silently dropping one it named is
            // the behaviour that makes a batch endpoint untrustworthy.
            return CovePlan::query()->whereIn('id', $data['ids'])->get();
        }

        return CovePlan::query()
            ->when(isset($data['market']), fn (Builder $q) => $q->where('market', $data['market']))
            ->when(isset($data['kinds']), fn (Builder $q) => $q->whereIn('kind', $data['kinds']))
            ->when(
                isset($data['state']),
                fn (Builder $q) => PlanState::scope($q, PlanState::from($data['state'])),
            )
            ->orderByRaw('drop_date is null')
            ->orderBy('drop_date')
            ->limit((int) ($data['limit'] ?? 10))
            ->get();
    }

    private function stage(string $stage): string
    {
        /*
         * `write` is deliberately absent.
         *
         * The prose comes from the caller, so there is nothing for the server to
         * batch — `POST /coves/{id}/editorial` is where words arrive, one plan
         * at a time because each carries different ones. `plan` is absent for
         * the same reason it has its own endpoint: drafting takes a source and a
         * count rather than a set of existing plans.
         */
        if (! in_array($stage, ['curate', 'approve', 'build'], true)) {
            throw ValidationException::withMessages([
                'stage' => "Unknown stage '{$stage}'. This runs curate, approve or build over a set. "
                    .'Prose goes to POST /coves/{id}/editorial, one plan at a time, because each carries different '
                    .'words; new plans come from POST /coves/drafts.',
            ]);
        }

        return $stage;
    }

    /**
     * Publishing stages need the publishing ability.
     *
     * Curating a draft cannot reach a reader; approving and building can. The
     * route group cannot express this because one path serves three stages, so
     * it is checked here — and checked before any work is done, so a refused run
     * changes nothing at all.
     */
    private function assertMayRun(Request $request, string $stage): void
    {
        if (in_array($stage, ['approve', 'build'], true)
            && AuthenticateApiToken::from($request)?->can(ApiToken::PUBLISH) !== true) {
            abort(403, "Running '{$stage}' over a set puts pages in front of readers and needs the "
                .'editorial.publish ability. A writing key may run `curate`.');
        }
    }
}
