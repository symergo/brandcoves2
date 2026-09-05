<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Enums\Source;
use App\Http\Controllers\Controller;
use App\Models\CovePlan;
use App\Models\CovePlanItem;
use App\Models\ProductGroup;
use App\Services\Cove\EditionBuilder;
use App\Services\Cove\PlanRevision;
use App\Services\Curation\PlanCurator;
use App\Services\Curation\ScheduleConflicts;
use App\Services\Editorial\HouseStyle;
use App\Services\Editorial\ProductLookup;
use App\Services\Identity\Gtin;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Curating a Cove from outside the panel.
 *
 * The curation screen has eleven actions and nine of them had no HTTP twin, so
 * an outside author could write about a shortlist and never change one. This is
 * the other half: add, remove, reorder, annotate, and ask the engine for more.
 *
 * ## Nothing here is a second implementation
 *
 * Every route delegates to the service the Livewire screen already calls —
 * `PlanCurator`, `EditionBuilder::candidates()`, `ScheduleConflicts`. That is
 * deliberate and load-bearing: two implementations of "add a product to a plan"
 * would disagree about market scoping, about which sources may be stored as a
 * decision, and about what a rank means, and only one of them would be the one
 * the panel shows.
 *
 * ## Why `POST /coves` was not enough
 *
 * It replaces the shortlist wholesale. That is right for "here is the plan" and
 * useless for "drop the third one": a client would have to re-send every item it
 * wants to keep, and a client that gets that slightly wrong silently discards a
 * curator's work. These are the narrow operations, and none of them can touch
 * the prose.
 */
class CoveItemController extends Controller
{
    public function __construct(
        private readonly PlanCurator $curator,
        private readonly EditionBuilder $builder,
        private readonly ProductLookup $lookup,
        private readonly ScheduleConflicts $conflicts,
    ) {}

    /**
     * Add one product to the shortlist, at the end.
     *
     * Three ways to name it, because an author has three kinds of handle and
     * only one of them is portable:
     *
     *   - `ean` — the same number in every market and every environment, and
     *     therefore the one to prefer in a brief;
     *   - `groupId` — this environment's id for it, resolved from a lookup;
     *   - `source` + `externalId` — a decision about a product whose catalogue
     *     may not be mirrored. Invariant 6.
     */
    public function store(Request $request, CovePlan $plan): JsonResponse
    {
        $data = $request->validate([
            'ean' => ['nullable', 'string', 'max:20'],
            'groupId' => ['nullable', 'integer'],
            'source' => ['nullable', Rule::in(Source::values())],
            'externalId' => ['nullable', 'string', 'max:100'],
            'note' => ['nullable', 'string', 'max:500'],
            'verdict' => ['nullable', 'string', 'max:80'],
            'copy' => ['nullable', 'string', 'max:500'],
        ]);

        $this->assertOpenForCuration($plan);

        $key = $this->key($plan, $data);

        try {
            $item = $this->curator->add($plan, $key, null, $data['note'] ?? null);
        } catch (\InvalidArgumentException $e) {
            // The service's own rules — market scope (invariant 2) and which
            // sources may be held as a decision (invariant 6) — reported as the
            // 422 they are rather than as a 500.
            throw ValidationException::withMessages(['product' => $e->getMessage()]);
        }

        if (filled($data['verdict'] ?? null) || filled($data['copy'] ?? null)) {
            $item->forceFill(array_filter([
                'verdict' => HouseStyle::plain($data['verdict'] ?? null),
                'copy' => HouseStyle::prose($data['copy'] ?? null),
            ], fn ($v) => $v !== null))->save();
        }

        return response()->json(['data' => $this->shortlist($plan->refresh())], 201);
    }

    /** Take one product off the shortlist. */
    public function destroy(CovePlan $plan, CovePlanItem $item): JsonResponse
    {
        $this->assertOpenForCuration($plan);
        $this->assertOwns($plan, $item);

        $this->curator->remove($item);

        return response()->json(['data' => $this->shortlist($plan->refresh())]);
    }

    /**
     * Reorder, and write what is said about each product.
     *
     * One call rather than three, because they are one editorial act: a curator
     * decides the running order and the reasons together, and a client that had
     * to make a request per item would spend a 20/min write budget on one page.
     *
     * `order` is the whole list. A partial one is not an error — `reorder()`
     * renumbers what it recognises — but it is almost always a stale brief, so
     * an id that is not on this plan is refused rather than skipped.
     */
    public function update(Request $request, CovePlan $plan): JsonResponse
    {
        $data = $request->validate([
            'revision' => ['nullable', 'string'],
            'order' => ['nullable', 'array', 'max:24'],
            'order.*' => ['integer'],
            'items' => ['nullable', 'array', 'max:24'],
            'items.*.id' => ['required', 'integer'],
            'items.*.note' => ['nullable', 'string', 'max:500'],
            'items.*.verdict' => ['nullable', 'string', 'max:80'],
            'items.*.copy' => ['nullable', 'string', 'max:500'],
        ]);

        $this->assertOpenForCuration($plan);

        /*
         * The revision is optional here and required on the prose endpoint.
         *
         * Reordering is idempotent and additive in a way writing is not — two
         * curators moving different products do not destroy each other's work,
         * whereas two writers do. It is honoured when sent, because a client
         * that quotes one is telling us it read the plan first.
         */
        if (filled($data['revision'] ?? null) && ! PlanRevision::matches($plan, $data['revision'])) {
            return response()->json([
                'message' => 'This plan changed while you were curating it. Re-read it and start again.',
                'data' => $this->shortlist($plan->fresh()),
            ], 409);
        }

        $mine = $plan->items()->pluck('id')->all();

        $strangers = array_diff(
            [...($data['order'] ?? []), ...array_column($data['items'] ?? [], 'id')],
            $mine,
        );

        if ($strangers !== []) {
            throw ValidationException::withMessages([
                'items' => 'Not items on this plan: '.implode(', ', array_unique($strangers)).'.',
            ]);
        }

        if (isset($data['order'])) {
            $this->curator->reorder($plan, $data['order']);
        }

        foreach ($data['items'] ?? [] as $item) {
            CovePlanItem::query()->whereKey($item['id'])->update(array_filter([
                // `note` is the curator's reason and reaches only the writer, so
                // it is stored as sent. `copy` and `verdict` are printed.
                'note' => $item['note'] ?? null,
                'verdict' => HouseStyle::plain($item['verdict'] ?? null),
                'copy' => HouseStyle::prose($item['copy'] ?? null),
            ], fn ($v) => $v !== null));
        }

        return response()->json(['data' => $this->shortlist($plan->refresh())]);
    }

    /**
     * Top the shortlist up with what the builder would have chosen.
     *
     * The blank page, answered the same way the panel answers it: a plan that
     * opens empty asks somebody to invent seven products from nothing, and a
     * plan that opens with the engine's guess asks them to react — which is a
     * job people, and models, are much better at.
     *
     * It only ever **adds**. Existing items keep their position and their notes,
     * and `candidates()` filters out anything already on the plan, so this is
     * safe to call on a plan somebody has already curated.
     */
    public function suggest(Request $request, CovePlan $plan): JsonResponse
    {
        $data = $request->validate([
            'count' => ['nullable', 'integer', 'min:1', 'max:24'],
        ]);

        $this->assertOpenForCuration($plan);

        $target = $plan->kind->targetItems();

        if ($target === 0) {
            throw ValidationException::withMessages([
                'plan' => 'A '.$plan->kind->label().' carries no products — its substance is the writing.',
            ]);
        }

        $want = (int) ($data['count'] ?? max(0, $target - $plan->items()->count()));

        if ($want < 1) {
            // Already at target. Not an error, and saying so beats a silent zero.
            return response()->json([
                'added' => 0,
                'message' => "That plan already carries {$target} products, which is what this kind aims for.",
                'data' => $this->shortlist($plan),
            ]);
        }

        $added = 0;

        foreach ($this->builder->candidates($plan, $want) as $group) {
            $this->curator->add($plan, 'group:'.$group->id);
            $added++;
        }

        return response()->json([
            'added' => $added,
            /*
             * Fewer than asked for is normal and needs saying, for the same
             * reason `DraftedPlans::shortfall` exists: a bare count cannot tell
             * "the catalogue is thin here" from "the request failed", and one of
             * those is worth retrying.
             */
            'shortfall' => $added < $want
                ? $this->whyShort($plan, $added)
                : null,
            'data' => $this->shortlist($plan->refresh()),
        ]);
    }

    /**
     * Where else these products are spoken for.
     *
     * Advisory, never a filter — two Coves a month apart may both want the same
     * kettle, and a rule that refused would be wrong more often than right. The
     * 90-day repeat memory catches this for anything the *engine* picks and
     * deliberately does not for anything a person picks, because overriding a
     * score is the whole point of curating. So telling the curator is the only
     * defence there is.
     */
    public function conflicts(CovePlan $plan): JsonResponse
    {
        $ids = $plan->items()->whereNotNull('group_id')->pluck('group_id')->all();

        return response()->json([
            'data' => $this->conflicts->for($plan->market, $ids, $plan->id),
        ]);
    }

    /**
     * Why the engine could not fill the page, in the caller's terms.
     *
     * Two very different causes look identical from a count of zero, and only
     * one of them is about the catalogue:
     *
     *   - the plan has **nothing to search on**. `LadderSelector` matches on
     *     `focus_keyphrase`, falling back to the title, plus `queries` — and a
     *     title is a headline. "Beste koptelefoons" is not a phrase any product
     *     title contains, so a plan carrying only that finds nothing however
     *     full the catalogue is.
     *   - the market genuinely has little to offer for this topic.
     *
     * Telling them apart is the difference between an author fixing the plan in
     * one call and an author concluding the catalogue is empty.
     */
    private function whyShort(CovePlan $plan, int $added): string
    {
        $terms = array_filter((array) $plan->queries, 'is_string');

        if (blank($plan->focus_keyphrase) && $terms === []) {
            return "Only {$added} more product(s) found, and this plan has nothing specific to search on: "
                .'it falls back to its title, which is a headline rather than words a product carries. '
                .'PATCH this plan with a `focusKeyphrase` (the thing itself, e.g. "koptelefoon") or '
                .'`queries` (the product words people type), then ask again.';
        }

        return "The catalogue in {$plan->market->value} could only offer {$added} more product(s) for "
            .'this topic. Widen the queries, or curate the rest by hand.';
    }

    // ── Plumbing ──────────────────────────────────────────────────────────

    /**
     * Turn whichever handle the caller sent into the key `PlanCurator` speaks.
     *
     * @param  array<string, mixed>  $data
     */
    private function key(CovePlan $plan, array $data): string
    {
        if (filled($data['ean'] ?? null)) {
            $gtin = Gtin::normalise((string) $data['ean']);

            if ($gtin === null) {
                throw ValidationException::withMessages([
                    'ean' => "'{$data['ean']}' fails its check digit — that is a misread rather than a product we do not carry.",
                ]);
            }

            $group = ProductGroup::query()
                ->forMarket($plan->market)
                ->where('identity_key', $gtin)
                ->first();

            if ($group === null) {
                throw ValidationException::withMessages([
                    'ean' => "No product with barcode {$gtin} in {$plan->market->value}. "
                        .'Try GET /products?ean='.$gtin.'&market='.$plan->market->value.'&includeLive=1 first, '
                        .'which asks the live sources and ingests what they return.',
                ]);
            }

            return 'group:'.$group->id;
        }

        if (filled($data['groupId'] ?? null)) {
            return 'group:'.(int) $data['groupId'];
        }

        if (filled($data['source'] ?? null) && filled($data['externalId'] ?? null)) {
            return $data['source'].':'.$data['externalId'];
        }

        throw ValidationException::withMessages([
            'product' => 'Name the product: an `ean` (portable across markets and environments, so prefer it), '
                .'a `groupId` from this host, or a `source` and `externalId` for a catalogue we may not mirror.',
        ]);
    }

    /**
     * A shortlist is only editable while the plan is still somebody's draft.
     *
     * Same rule the prose endpoints keep, and for the same reason: once a person
     * has approved a plan, changing what is on it is a publishing act. A key
     * that may write is not a key that may publish.
     */
    private function assertOpenForCuration(CovePlan $plan): void
    {
        if (in_array($plan->status, ['approved', 'used'], true)) {
            abort(403, "That plan is already {$plan->status} and has been reviewed. "
                .'Changing its shortlist now needs the editorial.publish ability.');
        }
    }

    private function assertOwns(CovePlan $plan, CovePlanItem $item): void
    {
        if ($item->plan_id !== $plan->id) {
            // A 422 rather than a quiet skip: an id from another plan means the
            // caller is working from a stale brief, and the rest of what it
            // believes about this plan is suspect too.
            throw ValidationException::withMessages([
                'item' => "Item {$item->id} is not on plan {$plan->id}.",
            ]);
        }
    }

    /** @return array<string, mixed> */
    private function shortlist(CovePlan $plan): array
    {
        return [
            'id' => $plan->id,
            'revision' => PlanRevision::of($plan),
            'pickMode' => $plan->pick_mode->value,
            'floor' => [
                'minimum' => $plan->kind->minimumItems(),
                'target' => $plan->kind->targetItems(),
                'curated' => $plan->items()->count(),
                'buildable' => $plan->isBuildable(),
            ],
            'items' => $plan->items()->with('group')->get()
                ->map(fn (CovePlanItem $item) => [
                    'id' => $item->id,
                    'rank' => $item->rank,
                    'note' => $item->note,
                    'copy' => $item->copy,
                    'verdict' => $item->verdict,
                    'source' => $item->source?->value,
                    'externalId' => $item->external_id,
                    'product' => $item->group === null ? null : $this->lookup->describe($item->group),
                ])->all(),
        ];
    }
}
