<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CovePlan;
use App\Models\CovePlanItem;
use App\Models\ProductGroup;
use App\Services\Cove\CovePrompt;
use App\Services\Cove\EditionBuilder;
use App\Services\Cove\PlanRevision;
use App\Services\Cove\PlanState;
use App\Services\Editorial\ProductLookup;
use Illuminate\Http\JsonResponse;

/**
 * The prompt this Cove would be written from — handed to whoever is writing it.
 *
 * The endpoint that makes "write it from the prompt in the database" true rather
 * than aspirational. `Operations → Prompts` holds an editable system prompt per
 * Cove kind, and until now only the built-in model could ever be given it: an
 * external author was told the same rules out of band, from four hand-maintained
 * copies that had already drifted apart.
 *
 * Now both writers ask {@see CovePrompt}. What comes back here is what
 * `EditionBuilder` would send, prompt-bank override included — so an edit to the
 * voice reaches Claude the same afternoon it reaches the builder, and there is
 * one contract instead of five descriptions of one.
 *
 * ## What `finds` means here, and the one caveat
 *
 * A prompt is about specific products, and which products a page ends up with is
 * only fully known when it is built. For a **locked** plan the shortlist *is* the
 * edition, so this is exact. For an **open** one the engine tops the list up on
 * the day, so the brief describes the curated part and the allowlist may widen
 * later — the same caveat the editorial API already documents for `linkCheck` on
 * a plan, and the reason an authored Cove usually wants `pickMode: locked`.
 *
 * Read-only, and it costs nothing: no model is called, here or anywhere else in
 * a request handler. Invariant 1.
 */
class CoveBriefController extends Controller
{
    public function __construct(
        private readonly CovePrompt $prompt,
        private readonly EditionBuilder $builder,
        private readonly ProductLookup $lookup,
    ) {}

    /**
     * What this plan actually became.
     *
     * `GET /editions/{market}/{date}` reads back a **Daily** and nothing else,
     * so every slug-addressed kind — persona, guide, seasonal, advice, shop —
     * had no read-back at all: an author was told to fetch the public HTML page
     * and look at it. That is no answer for an unattended run, and it cannot
     * report the two things that matter most.
     *
     * The first is whether the build produced a page. `queued` is not `built`: a
     * catalogue too thin to clear the kind's floor is decided inside the job,
     * minutes later, and used to surface only as a log line.
     *
     * The second is `theme.source`. `planned` means the plan won; anything else
     * means it did not, and the most likely reason is that nobody approved it.
     */
    public function edition(CovePlan $plan): JsonResponse
    {
        $edition = $plan->edition;
        $state = PlanState::of($plan);

        return response()->json([
            'data' => [
                'id' => $plan->id,
                'state' => $state->value,
                'nextStage' => $state->nextStage(),

                /*
                 * Why the last build produced nothing, when it produced nothing.
                 *
                 * The whole point of the read-back for a scheduled caller: this
                 * is the difference between "published" and "nothing happened",
                 * which every screen and every response used to report as
                 * neither.
                 */
                'lastBuild' => $plan->last_build_failed_at === null ? null : [
                    'failedAt' => $plan->last_build_failed_at->toIso8601String(),
                    'why' => $plan->last_build_note,
                ],

                'edition' => $edition === null ? null : [
                    'id' => $edition->id,
                    'status' => $edition->status->value,
                    'publishedAt' => $edition->published_at?->toIso8601String(),
                    'url' => '/'.$plan->market->value.'/'
                        .$plan->kind->path((string) ($edition->slug ?? $plan->slug), $plan->market),
                    // `planned` means the plan won. Anything else means it did
                    // not, and usually that nobody approved it.
                    'themeSource' => $edition->theme_source,
                    'editorialSource' => $edition->editorial_source,
                    'picks' => $edition->picks()->count(),
                ],
            ],
        ]);
    }

    public function show(CovePlan $plan): JsonResponse
    {
        $finds = $this->finds($plan);
        $assembled = $this->prompt->forPlan($plan, $finds);

        return response()->json([
            'data' => [
                'id' => $plan->id,
                'market' => $plan->market->value,
                'language' => $plan->market->language(),
                'kind' => $plan->kind->value,
                'title' => $plan->title,
                'writer' => $plan->writer->value,

                /*
                 * Quote this back when you post the prose.
                 *
                 * Same token `POST /coves/{id}/editorial` checks, from the same
                 * service, so a brief fetched here can be written back without a
                 * second round trip through the queue endpoint — which lists
                 * only plans that have no prose yet and therefore could never
                 * hand out a revision for the one you wanted to revise.
                 */
                'revision' => PlanRevision::of($plan),

                /*
                 * The prompt itself, both halves, exactly as the builder sends
                 * them.
                 *
                 * `system` carries the voice (editable, per kind), the paragraph
                 * contract (not editable — a prompt edit may change how a Cove
                 * sounds and must not be able to drop the rule that decides
                 * whether its cards render), and this piece's link allowlist.
                 * `user` is the brief: language, title, occasion, the editor's
                 * direction and the products.
                 */
                'prompt' => [
                    'system' => $assembled['system'],
                    'user' => $assembled['user'],
                ],

                /*
                 * What the page has to clear, so an author knows before writing
                 * whether it can publish at all.
                 *
                 * A locked guide with four products does not build, and finding
                 * that out from a build that logged a warning at 07:00 is the
                 * failure this whole surface exists to avoid.
                 */
                'floor' => [
                    'minimum' => $plan->kind->minimumItems(),
                    'target' => $plan->kind->targetItems(),
                    'curated' => count($finds),
                    'buildable' => $plan->isBuildable(),
                ],

                /*
                 * The shortlist, with both of the things written about it: the
                 * curator's reason (`note`, never shown to a reader) and the
                 * sentence printed under the card (`copy`).
                 */
                'items' => $plan->items()->with('group')->get()
                    ->map(fn (CovePlanItem $item) => [
                        'id' => $item->id,
                        'rank' => $item->rank,
                        'note' => $item->note,
                        'copy' => $item->copy,
                        'verdict' => $item->verdict,
                        'product' => $item->group === null ? null : $this->lookup->describe($item->group),
                    ])->all(),

                'allowlist' => $this->prompt->allowlist($plan->market, $finds),
            ],
        ]);
    }

    /**
     * The products this brief is written about.
     *
     * The plan's own shortlist for a locked plan — that is the edition. For an
     * open one, what the builder would choose today, so the brief describes a
     * realistic page rather than only the curated head of one.
     *
     * @return list<ProductGroup>
     */
    private function finds(CovePlan $plan): array
    {
        $curated = $plan->items()
            ->whereNotNull('group_id')
            ->with('group')
            ->get()
            ->map(fn (CovePlanItem $item) => $item->group)
            ->filter()
            ->values()
            ->all();

        if ($plan->pick_mode->value === 'locked' || $plan->kind->targetItems() === 0) {
            return $curated;
        }

        /*
         * `candidates()` is the same selection the builder would make, and it
         * already excludes anything on the plan — so this is the curated head
         * followed by the engine's guess at the tail, in the order the page
         * would carry them.
         */
        return [
            ...$curated,
            ...$this->builder->candidates($plan, max(0, $plan->kind->targetItems() - count($curated))),
        ];
    }
}
