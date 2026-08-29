<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Enums\CoveKind;
use App\Enums\Market;
use App\Enums\PickMode;
use App\Enums\Source;
use App\Http\Controllers\Controller;
use App\Http\Middleware\AuthenticateApiToken;
use App\Jobs\BuildDailyEdition;
use App\Jobs\BuildPersonaCove;
use App\Models\ApiToken;
use App\Models\CovePlan;
use App\Models\CovePlanItem;
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
        $kind = CoveKind::from($data['kind'] ?? CoveKind::Daily->value);
        $date = $data['date'] ?? null;
        $slug = $data['slug'] ?? null;

        /*
         * A persona has no date and a Daily has no slug.
         *
         * Refused rather than reconciled, because both silent fixes are worse
         * than the error: dropping the date would publish something the author
         * scheduled, and keeping it would hand the morning build a persona.
         */
        if ($kind === CoveKind::Persona) {
            if ($date !== null) {
                throw ValidationException::withMessages([
                    'date' => 'A gift persona has no date — it is permanent. Send a slug instead.',
                ]);
            }

            if ($slug === null) {
                throw ValidationException::withMessages([
                    'slug' => 'A gift persona needs a slug: it is the permanent URL the page lives at.',
                ]);
            }
        }

        $existing = match (true) {
            $kind === CoveKind::Persona => CovePlan::persona($market, (string) $slug),
            $date === null => null,
            default => CovePlan::query()
                ->where('market', $market->value)
                ->where('kind', CoveKind::Daily->value)
                ->whereDate('drop_date', $date)
                ->first(),
        };

        if ($existing !== null) {
            $this->assertMayEdit($request, $existing);
        }

        $items = $this->resolveItems($market, $data);
        $curated = $items->pluck('group')->filter()->values();

        $attributes = [
            'market' => $market->value,
            'kind' => $kind->value,
            'drop_date' => $kind === CoveKind::Persona ? null : $date,
            'slug' => $kind === CoveKind::Persona ? $slug : null,
            'pick_mode' => $data['pickMode'] ?? PickMode::Open->value,
            'title' => $data['title'],
            'blurb' => $data['blurb'] ?? null,
            'editorial' => $data['editorial'] ?? null,
            'build_instructions' => $data['buildInstructions'] ?? null,
            'queries' => $data['queries'] ?? [],
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

        /*
         * Replace the shortlist, never merge into it.
         *
         * A write says what the plan is, not what to add to it — a merge would
         * make "remove the third product" impossible to express, and a retry
         * after a timeout would double the list.
         */
        $plan->items()->delete();

        foreach ($items as $rank => $item) {
            $plan->items()->create([
                'group_id' => $item['group']?->id,
                'source' => $item['source'],
                'external_id' => $item['externalId'],
                'rank' => $rank + 1,
                'note' => $item['note'],
                'verdict' => $item['verdict'],
            ]);
        }

        return response()->json([
            'data' => $this->payload($plan),
            // The plan's own queries count as linkable searches: an author who
            // wrote `queries: ["espressomachine"]` has already said what the day
            // is about, and `[[search:espressomachine]]` should follow from it.
            'linkCheck' => $this->links->against(
                $plan->editorial,
                $market,
                $curated,
                extraSearches: (array) $plan->queries,
            ),
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
        $queued = $request->boolean('build') && ($plan->drop_date !== null || $plan->isPersona());

        if ($queued) {
            $this->queueBuild($plan);
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
        if ($plan->drop_date === null && ! $plan->isPersona()) {
            throw ValidationException::withMessages([
                'plan' => 'That plan has no date and is not a persona. Give it one, or make it a persona with a slug.',
            ]);
        }

        if ($plan->status !== 'approved') {
            throw ValidationException::withMessages([
                'plan' => "Only an approved plan is built. This one is '{$plan->status}'.",
            ]);
        }

        $this->queueBuild($plan);

        return response()->json([
            'message' => 'Build queued.',
            'market' => $plan->market->value,
            'date' => $plan->drop_date?->toDateString(),
            'slug' => $plan->slug,
            'readBack' => $plan->isPersona()
                ? '/'.$plan->market->value.'/gift-ideas/'.$plan->slug
                : "/api/editorial/editions/{$plan->market->value}/{$plan->drop_date->toDateString()}",
        ], 202);
    }

    /**
     * Dispatch the right build for this plan's kind.
     *
     * A persona does not go through BuildDailyEdition: that job also mines
     * guide topics and seeds the seasonal ones, both of which are about the
     * day, and a persona has none.
     */
    private function queueBuild(CovePlan $plan): void
    {
        $plan->isPersona()
            ? BuildPersonaCove::dispatch($plan->id)
            : BuildDailyEdition::dispatch($plan->market, $plan->drop_date->toDateString());
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
                'max:'.(int) config('giftcoves.editorial_api.max_editorial_chars'),
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

            /*
             * A gift persona is a Cove with no date, addressed by a slug.
             *
             * Rejected rather than silently ignored when the two disagree: a
             * dated persona would be picked up by the morning build and
             * published as that day's Daily Cove.
             */
            'kind' => ['nullable', Rule::in(CoveKind::values())],
            'slug' => ['nullable', 'string', 'max:80', 'alpha_dash'],
            'pickMode' => ['nullable', Rule::in(PickMode::values())],

            /*
             * Direction for whoever writes the prose, including a later call
             * from the same key. Capped short on purpose: it is a brief, and a
             * brief long enough to be an article is an article.
             */
            'buildInstructions' => ['nullable', 'string', 'max:1000'],

            /*
             * The curated shortlist: what the article is written about.
             *
             * Ordered — position is part of the curation — and each entry may
             * carry the reason it is there, which is handed to the writer.
             */
            'items' => ['nullable', 'array', 'max:24'],
            'items.*.groupId' => ['nullable', 'integer'],
            'items.*.source' => ['nullable', Rule::in(Source::values())],
            'items.*.externalId' => ['nullable', 'string', 'max:100'],
            'items.*.note' => ['nullable', 'string', 'max:500'],
            'items.*.verdict' => ['nullable', 'string', 'max:80'],

            // The pre-items field. Still accepted, and written as items, so a
            // key deployed before this change keeps working unchanged.
            'pinnedGroupIds' => ['nullable', 'array', 'max:12'],
            'pinnedGroupIds.*' => ['integer'],

            'note' => ['nullable', 'string', 'max:1000'],
        ]);
    }

    /**
     * Turn the submitted shortlist into resolvable items, or fail the whole write.
     *
     * All or nothing on purpose. Silently dropping an id that does not resolve
     * leaves an article whose second paragraph discusses a product that is not
     * on the page — the exact failure the ids exist to prevent.
     *
     * Both shapes are accepted. `items` is the current one; `pinnedGroupIds` is
     * what keys written before curation existed still send, and it means the
     * same thing with no note and no ordering beyond its own.
     *
     * @param  array<string, mixed>  $data
     * @return Collection<int, array{group: ProductGroup|null, source: string|null, externalId: string|null, note: string|null, verdict: string|null}>
     */
    private function resolveItems(Market $market, array $data): Collection
    {
        $submitted = collect((array) ($data['items'] ?? []));

        /*
         * Errors are reported under the field the caller actually sent.
         *
         * A key written before curation existed sends `pinnedGroupIds`, and
         * being told its mistake is in `items` — a field it has never heard of
         * — is a worse error message than no error message.
         */
        $field = 'items';

        if ($submitted->isEmpty()) {
            $field = 'pinnedGroupIds';
            $submitted = collect((array) ($data['pinnedGroupIds'] ?? []))
                ->map(fn ($id) => ['groupId' => $id]);
        }

        if ($submitted->isEmpty()) {
            return collect();
        }

        $ids = $submitted
            ->pluck('groupId')
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();

        if ($ids !== []) {
            $rejected = $this->lookup->rejectUnusable($market, $ids);

            if ($rejected !== []) {
                throw ValidationException::withMessages([
                    $field => 'Not usable in '.$market->value.': '.implode(', ', $rejected).
                        '. A product must exist in this market, be in stock, priced and have an image. '.
                        'Product ids are per market — the same product elsewhere is a different id.',
                ]);
            }
        }

        $groups = ProductGroup::query()->whereIn('id', $ids)->get()->keyBy('id');

        return $submitted->map(function (array $item) use ($groups, $field): array {
            $groupId = isset($item['groupId']) ? (int) $item['groupId'] : null;
            $source = $item['source'] ?? null;
            $externalId = $item['externalId'] ?? null;

            if ($groupId === null && ($source === null || $externalId === null)) {
                throw ValidationException::withMessages([
                    $field => 'Every item needs either a groupId, or a source and an externalId.',
                ]);
            }

            /*
             * An external id is only meaningful for a source we may not mirror.
             *
             * Anything else already has a group id, with offers, a price
             * history and a comparison behind it; storing it by external id
             * would make a second, unlinked copy of a product the site can
             * already compare properly.
             */
            if ($groupId === null && Source::from($source)->allowsCatalogueStorage()) {
                throw ValidationException::withMessages([
                    $field => "{$source} is in the catalogue — send its groupId. An externalId is only for a source whose catalogue may not be stored.",
                ]);
            }

            return [
                'group' => $groupId === null ? null : $groups[$groupId],
                'source' => $groupId === null ? $source : null,
                'externalId' => $groupId === null ? $externalId : null,
                'note' => $item['note'] ?? null,
                'verdict' => $item['verdict'] ?? null,
            ];
        })->values();
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
            'kind' => $plan->kind->value,
            'date' => $plan->drop_date?->toDateString(),
            'slug' => $plan->slug,
            'pickMode' => $plan->pick_mode->value,
            'title' => $plan->title,
            'status' => $plan->status,
            'curatedCount' => $plan->items()->count(),
            'hasEditorial' => filled($plan->editorial),
            'edition' => $plan->edition === null ? null : [
                'id' => $plan->edition->id,
                'status' => $plan->edition->status->value,
                // A persona lives at its slug and has no date to build a URL
                // from — the same split as the routes.
                'url' => $plan->edition->drop_date === null
                    ? '/'.$plan->market->value.'/gift-ideas/'.$plan->edition->slug
                    : '/'.$plan->market->value.'/daily/'.$plan->edition->slug,
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function payload(CovePlan $plan): array
    {
        return [
            ...$this->summary($plan),
            'blurb' => $plan->blurb,
            'editorial' => $plan->editorial,
            'buildInstructions' => $plan->build_instructions,
            'queries' => $plan->queries,
            'note' => $plan->note,

            /*
             * The shortlist, in order, with the reason each entry is on it.
             *
             * Read back deliberately: an author whose next call writes the
             * prose needs the ids it may link to and the notes it is meant to
             * turn into sentences, and asking for them is one request rather
             * than a search per product.
             */
            'items' => $plan->items()->with('group')->get()
                ->map(fn (CovePlanItem $item) => [
                    'rank' => $item->rank,
                    'note' => $item->note,
                    'verdict' => $item->verdict,
                    'source' => $item->source?->value,
                    'externalId' => $item->external_id,
                    'product' => $item->group === null ? null : $this->lookup->describe($item->group),
                ])->all(),

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
