<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Enums\Market;
use App\Http\Controllers\Controller;
use App\Http\Middleware\AuthenticateApiToken;
use App\Jobs\BuildDailyEdition;
use App\Models\ApiToken;
use App\Models\CovePlan;
use App\Models\ProductGroup;
use App\Services\Cove\ObservanceCalendar;
use App\Services\Editorial\LinkCheck;
use App\Services\Editorial\ProductLookup;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Writing a Daily Cove from outside the box.
 *
 * ## Why this writes a plan and not an edition
 *
 * A `cove_plans` row is an *intention*; the edition is what readers get. That
 * split already existed for the editorial calendar and it is exactly what an
 * external author needs: the plan can be written days ahead, reviewed, revised
 * and rejected, and the builder still decides whether the catalogue can carry
 * it on the day. An API that wrote editions directly would be an API that can
 * publish a page with three products on it because the feed had a bad night.
 *
 * ## Why it does not call the model
 *
 * Nothing here touches AI. The prose arrives already written; the build is
 * dispatched to the queue. That is not incidental — a request handler that can
 * cause AI spend is the invariant this codebase is most careful about, and an
 * endpoint whose whole job is "make me an article" is the most tempting place
 * in the app to break it. A Cove written through this API costs nothing at all,
 * because the one part that used a model is the part the author supplied.
 *
 * See docs/features/editorial-api.md and docs/features/ai-invariant.md.
 */
class CovePlanController extends Controller
{
    public function __construct(
        private readonly ProductLookup $lookup,
        private readonly LinkCheck $links,
    ) {}

    /** The calendar: what is planned, and what became of it. */
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'market' => ['nullable', Rule::in(Market::values())],
            'status' => ['nullable', Rule::in(['draft', 'approved', 'used', 'rejected'])],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $plans = CovePlan::query()
            ->with('edition:id,drop_date,status,theme_title')
            ->when(isset($data['market']), fn ($q) => $q->where('market', $data['market']))
            ->when(isset($data['status']), fn ($q) => $q->where('status', $data['status']))
            ->when(isset($data['from']), fn ($q) => $q->whereDate('drop_date', '>=', $data['from']))
            ->when(isset($data['to']), fn ($q) => $q->whereDate('drop_date', '<=', $data['to']))
            ->orderByRaw('drop_date is null')
            ->orderBy('drop_date')
            ->limit((int) ($data['limit'] ?? 50))
            ->get();

        return response()->json([
            'count' => $plans->count(),
            'data' => $plans->map(fn (CovePlan $p) => $this->summary($p))->all(),
        ]);
    }

    public function show(CovePlan $plan): JsonResponse
    {
        return response()->json(['data' => $this->payload($plan)]);
    }

    /**
     * Write or rewrite the plan for a date.
     *
     * Upsert rather than create, because the unique index allows exactly one
     * dated plan per market per day and a writer retrying after a timeout must
     * not get a constraint violation for work it already did. Undated plans —
     * ideas with no slot yet — are always new rows; the queue may hold as many
     * as it likes.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        $market = Market::from($data['market']);
        $date = $data['date'] ?? null;

        $existing = $date === null
            ? null
            : CovePlan::query()
                ->where('market', $market->value)
                ->whereDate('drop_date', $date)
                ->first();

        if ($existing !== null) {
            $this->assertMayEdit($request, $existing);
        }

        $pinned = $this->resolvePinned($market, $data['pinnedGroupIds'] ?? []);

        $attributes = [
            'market' => $market->value,
            'drop_date' => $date,
            'title' => $data['title'],
            'blurb' => $data['blurb'] ?? null,
            'editorial' => $data['editorial'] ?? null,
            'queries' => $data['queries'] ?? [],
            'pinned_group_ids' => $pinned->pluck('id')->all(),
            'note' => $data['note'] ?? null,
        ];

        if ($existing === null) {
            // Always a draft. A key that may write is not a key that may
            // publish, and the two are separated at the route rather than left
            // to a flag in a body someone will eventually set by accident.
            $plan = CovePlan::create([...$attributes, 'status' => 'draft']);
        } else {
            $existing->update($attributes);
            $plan = $existing->refresh();
        }

        return response()->json([
            'data' => $this->payload($plan),
            'linkCheck' => $this->links->against($plan->editorial, $market, $pinned),
        ], $existing === null ? 201 : 200);
    }

    /**
     * Approve a plan, so the builder will use it.
     *
     * `approved` is the only status the builder picks up. Until this call, a
     * plan is someone thinking out loud — including a machine thinking out
     * loud, which is the case this endpoint exists to keep separable.
     */
    public function approve(Request $request, CovePlan $plan): JsonResponse
    {
        if ($plan->status === 'used') {
            throw ValidationException::withMessages([
                'plan' => 'That plan has already been used by an edition. Write a new one for another date.',
            ]);
        }

        $plan->update(['status' => 'approved']);

        // Approving and building are separate calls, but wanting both in one
        // round trip is the normal case for an author who has just finished
        // writing and wants to look at the result.
        $queued = $request->boolean('build') && $plan->drop_date !== null;

        if ($queued) {
            BuildDailyEdition::dispatch($plan->market, $plan->drop_date->toDateString());
        }

        return response()->json([
            'data' => $this->payload($plan->refresh()),
            'buildQueued' => $queued,
        ]);
    }

    /**
     * Queue the build for this plan's date.
     *
     * Dispatched, never run inline: the builder mines topics, may build a guide
     * and may call a model for a theme, and none of that belongs in the seconds
     * an HTTP client is willing to wait. Idempotent — the builder updates the
     * edition for a date in place rather than creating a second one, so a
     * client that retries gets one edition, not two.
     */
    public function build(CovePlan $plan): JsonResponse
    {
        if ($plan->drop_date === null) {
            throw ValidationException::withMessages([
                'plan' => 'That plan has no date. Give it one before building — an edition is a day.',
            ]);
        }

        if ($plan->status !== 'approved') {
            throw ValidationException::withMessages([
                'plan' => "Only an approved plan is built. This one is '{$plan->status}'.",
            ]);
        }

        BuildDailyEdition::dispatch($plan->market, $plan->drop_date->toDateString());

        return response()->json([
            'message' => 'Build queued.',
            'market' => $plan->market->value,
            'date' => $plan->drop_date->toDateString(),
            'readBack' => "/api/editorial/editions/{$plan->market->value}/{$plan->drop_date->toDateString()}",
        ], 202);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        return $request->validate([
            'market' => ['required', Rule::in(Market::values())],

            // Undated is legal: an idea waiting for a slot.
            'date' => ['nullable', 'date_format:Y-m-d'],

            'title' => ['required', 'string', 'max:120'],

            // One line. It becomes the meta description, and a paragraph in a
            // <meta> tag is a paragraph Google truncates mid-word.
            'blurb' => ['nullable', 'string', 'max:300'],

            'editorial' => [
                'nullable',
                'string',
                'max:'.(int) config('brandcoves.editorial_api.max_editorial_chars'),
            ],

            /*
             * Product words, not theme words.
             *
             * These are run through websearch_to_tsquery against the catalogue,
             * so "hondenmand" finds products and "cadeau voor hondenliefhebbers"
             * finds nothing. A bias on the finds, never a filter.
             */
            'queries' => ['nullable', 'array', 'max:12'],
            'queries.*' => ['string', 'max:60'],

            'pinnedGroupIds' => ['nullable', 'array', 'max:12'],
            'pinnedGroupIds.*' => ['integer'],

            'note' => ['nullable', 'string', 'max:1000'],
        ]);
    }

    /**
     * Turn pinned ids into products, or fail the whole write.
     *
     * All or nothing on purpose. Silently dropping an id that does not resolve
     * leaves an article whose second paragraph discusses a product that is not
     * on the page — the exact failure the ids exist to prevent.
     *
     * @param  list<int>  $ids
     * @return Collection<int, ProductGroup>
     */
    private function resolvePinned(Market $market, array $ids): Collection
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));

        if ($ids === []) {
            return collect();
        }

        $rejected = $this->lookup->rejectUnusable($market, $ids);

        if ($rejected !== []) {
            throw ValidationException::withMessages([
                'pinnedGroupIds' => 'Not usable in '.$market->value.': '.implode(', ', $rejected).
                    '. A product must exist in this market, be in stock, priced and have an image. '.
                    'Product ids are per market — the same product elsewhere is a different id.',
            ]);
        }

        // Ordered as the author gave them: a pin is a curation decision and its
        // position is part of the decision.
        $groups = ProductGroup::query()->whereIn('id', $ids)->get()->keyBy('id');

        return collect($ids)->map(fn (int $id) => $groups[$id])->values();
    }

    /**
     * A write-capable token may not edit something already approved.
     *
     * Otherwise the split between drafting and publishing is decorative: draft
     * a plan, wait for a human to approve it, then rewrite the contents. The
     * approved row is the reviewed row, and changing it is a publishing act.
     */
    private function assertMayEdit(Request $request, CovePlan $plan): void
    {
        if (! in_array($plan->status, ['approved', 'used'], true)) {
            return;
        }

        if (AuthenticateApiToken::from($request)?->can(ApiToken::PUBLISH) === true) {
            return;
        }

        abort(403, "That plan is already {$plan->status} and has been reviewed. ".
            'Rewriting it needs the editorial.publish ability.');
    }

    /** @return array<string, mixed> */
    private function summary(CovePlan $plan): array
    {
        return [
            'id' => $plan->id,
            'market' => $plan->market->value,
            'date' => $plan->drop_date?->toDateString(),
            'title' => $plan->title,
            'status' => $plan->status,
            'pinnedCount' => count((array) $plan->pinned_group_ids),
            'hasEditorial' => filled($plan->editorial),
            'edition' => $plan->edition === null ? null : [
                'id' => $plan->edition->id,
                'status' => $plan->edition->status->value,
                'url' => '/'.$plan->market->value.'/daily/'.$plan->edition->drop_date->toDateString(),
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function payload(CovePlan $plan): array
    {
        $pinned = $plan->pinned_group_ids === []
            ? collect()
            : ProductGroup::query()->whereIn('id', $plan->pinned_group_ids)->get();

        return [
            ...$this->summary($plan),
            'blurb' => $plan->blurb,
            'editorial' => $plan->editorial,
            'queries' => $plan->queries,
            'note' => $plan->note,
            'pinned' => $pinned->map(fn (ProductGroup $g) => $this->lookup->describe($g))->all(),

            /*
             * What the calendar already thinks this day is.
             *
             * An approved plan outranks an observance, so an author who does
             * not know that 31 October is Halloween can override it without
             * meaning to. Showing it is cheaper than explaining it afterwards.
             */
            'calendarTheme' => $plan->drop_date === null ? null : $this->calendarTheme($plan),
        ];
    }

    private function calendarTheme(CovePlan $plan): ?string
    {
        $observance = app(ObservanceCalendar::class)->themeFor(
            CarbonImmutable::instance($plan->drop_date),
            $plan->market,
        );

        return $observance?->title($plan->market);
    }
}
