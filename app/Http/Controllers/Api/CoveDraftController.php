<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Enums\CoveKind;
use App\Enums\Market;
use App\Http\Controllers\Controller;
use App\Models\CovePlan;
use App\Services\Cove\PlanDrafter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * "Give me ten Coves to write about."
 *
 * The other end of the writing loop from `CoveQueueController`. That one hands
 * out the plans that need prose; this one *makes* the plans, from the sources
 * that already know what is worth writing about here — the observance calendar,
 * the mined topic queue, and the interest vocabulary the gift wizard runs on.
 *
 * ## Why an agent should call this rather than invent titles
 *
 * A writing agent asked to think of ten guide topics will think of ten
 * plausible ones. The topic queue knows which phrases people typed into *this*
 * site and how many products exist to answer them — a demand signal no model
 * has and no competitor can measure. Drafting from it means the ten Coves that
 * get written are the ten somebody was looking for, and each arrives with a
 * shortlist of real, in-stock, priced products with ids the writer may link to.
 *
 * The loop this completes: draft here, read the briefs from
 * `GET /coves/queue`, write the prose back to `POST /coves/{id}/editorial`,
 * and — with a key that holds the publish ability — approve and build.
 *
 * ## It cannot publish, and it costs nothing
 *
 * Every plan is a draft, exactly like one typed into the panel. Nothing here
 * calls a model: this endpoint reads rows and writes rows. The prose is the
 * expensive part and it happens on the caller's side of the wire, which is what
 * makes the whole arrangement compatible with invariant 1 — a request handler
 * on this server never causes AI spend.
 *
 * See docs/features/editorial-api.md and docs/features/cove-planner.md.
 */
class CoveDraftController extends Controller
{
    public function __construct(private readonly PlanDrafter $drafter) {}

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'market' => ['required', Rule::in(Market::values())],
            'kind' => ['required', Rule::in(CoveKind::values())],

            /*
             * Capped at 50, which is the same ceiling the panel offers.
             *
             * Not because more would break, but because a draft nobody curates
             * is worse than no draft: it fills the planner with rows that look
             * like decisions and are not, and the queue endpoint will keep
             * handing them to a writer forever.
             */
            'count' => ['required', 'integer', 'min:1', 'max:50'],

            /*
             * The shortlists are the slow part — a ranked selection query per
             * plan. Worth turning off when the caller intends to curate the
             * products itself through POST /coves.
             */
            'withProducts' => ['nullable', 'boolean'],

            /*
             * Named days only, for a `daily` run.
             *
             * "Give me ten daily topics" almost always means ten *occasions*.
             * `ObservanceCalendar::themeFor()` falls back to the evergreen
             * rotation for any date with no named day, so an unfiltered walk
             * hands back the next ten unplanned **dates** — about
             * three-quarters of them rotation themes, which claim nothing about
             * their date and give a curator nothing to react to. The Cove
             * calendar screen hides all 270 of them for the same reason.
             *
             * Off by default, because the calendar still wants every day filled
             * and that is what `bc:plan-coves` is for.
             */
            'occasionsOnly' => ['nullable', 'boolean'],
        ]);

        $kind = CoveKind::from($data['kind']);
        $market = Market::from($data['market']);

        $result = $this->drafter->draft(
            $kind,
            $market,
            (int) $data['count'],
            author: null,
            withProducts: $request->boolean('withProducts', true),
            occasionsOnly: $request->boolean('occasionsOnly'),
        );

        if (! $this->drafter->canDraft($kind)) {
            /*
             * 422 with the reason, not an empty 200.
             *
             * An agent that receives "0 drafted" retries; one that is told
             * advice articles have no source a machine can read moves on and
             * writes the titles itself, which is the correct behaviour. The
             * drafter wrote nothing for these kinds — it refuses before it
             * reaches the database — so asking it is how the reason is fetched.
             */
            throw ValidationException::withMessages(['kind' => (string) $result->shortfall]);
        }

        return response()->json([
            'count' => $result->count(),
            'suggestedProducts' => $result->suggested,

            /*
             * Why there are fewer than were asked for, when there are.
             *
             * The single most useful field for a scheduled caller: it is the
             * difference between "the queue is exhausted, stop asking" and "the
             * request failed, retry", and a bare count cannot tell them apart.
             */
            'shortfall' => $result->shortfall,

            // Ids, so the next call can be `GET /coves/queue` or a straight
            // read of one plan, without re-deriving what was just created.
            'data' => array_map(fn (CovePlan $plan) => [
                'id' => $plan->id,
                'kind' => $plan->kind->value,
                'market' => $plan->market->value,
                'date' => $plan->drop_date?->toDateString(),
                'slug' => $plan->slug,
                'title' => $plan->title,
                'status' => $plan->status,
                'queries' => $plan->queries ?? [],
                'curatedCount' => $plan->items()->count(),
                'note' => $plan->note,
            ], $result->plans),
        ], $result->count() === 0 ? 200 : 201);
    }
}
