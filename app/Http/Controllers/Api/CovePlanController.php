<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Enums\CoveKind;
use App\Enums\CoveScene;
use App\Enums\Market;
use App\Enums\PickMode;
use App\Enums\PlanWriter;
use App\Enums\Source;
use App\Http\Controllers\Controller;
use App\Http\Middleware\AuthenticateApiToken;
use App\Jobs\BuildCove;
use App\Jobs\BuildDailyEdition;
use App\Models\ApiToken;
use App\Models\CovePlan;
use App\Models\CovePlanItem;
use App\Models\ProductGroup;
use App\Services\Cove\ObservanceCalendar;
use App\Services\Cove\PlanRevision;
use App\Services\Cove\PlanState;
use App\Services\Editorial\HouseStyle;
use App\Services\Editorial\LinkCheck;
use App\Services\Editorial\ProductLookup;
use App\Services\Shops\ShopDirectory;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Writing a Cove — any kind of Cove — from outside the box.
 *
 * It began as the Daily's endpoint and grew a persona; every kind added since
 * arrived silently addressed as a Daily, so a buying guide POSTed with a slug
 * was stored with a date and no address and answered 201. It now asks the enum
 * how a kind is addressed, which is the same question `CoveKind` answers for
 * the router, the sitemap and the planner.
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
        private readonly ShopDirectory $shops,
    ) {}

    /** The calendar: what is planned, and what became of it. */
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'market' => ['nullable', Rule::in(Market::values())],
            'kind' => ['nullable', Rule::in(CoveKind::values())],
            'status' => ['nullable', Rule::in(['draft', 'approved', 'used', 'rejected'])],

            /*
             * The vocabulary an editor and a caller actually think in.
             *
             * `status` has four values and answers neither "has this been
             * written yet" nor "did the build work" — both live in other
             * columns, and the second is the difference between a page that is
             * coming and one that quietly did not happen. One implementation,
             * shared with the planner screen: see App\Services\Cove\PlanState.
             */
            'state' => ['nullable', Rule::in(PlanState::values())],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $plans = CovePlan::query()
            ->with('edition:id,drop_date,status,theme_title')
            ->when(isset($data['market']), fn ($q) => $q->where('market', $data['market']))
            ->when(isset($data['kind']), fn ($q) => $q->where('kind', $data['kind']))
            ->when(isset($data['status']), fn ($q) => $q->where('status', $data['status']))
            ->when(isset($data['state']), fn ($q) => PlanState::scope($q, PlanState::from($data['state'])))
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
     * Change the plan's own settings, and nothing else.
     *
     * `POST /coves` is an upsert of the *whole* plan: it replaces the shortlist
     * wholesale, so "make this one locked" through that endpoint means re-sending
     * every product — and a client that gets that slightly wrong silently
     * discards somebody's curation. There was no way to flip `pickMode` or
     * `writer` without taking that risk.
     *
     * Only fields that are sent are touched, so this is safe to call with one
     * key in the body. It cannot reach the prose, the shortlist or the address.
     */
    public function patch(Request $request, CovePlan $plan): JsonResponse
    {
        $data = $request->validate([
            'title' => ['nullable', 'string', 'max:120'],
            'blurb' => ['nullable', 'string', 'max:300'],
            'note' => ['nullable', 'string', 'max:1000'],
            'buildInstructions' => ['nullable', 'string', 'max:1000'],
            'pickMode' => ['nullable', Rule::in(PickMode::values())],
            'writer' => ['nullable', Rule::in(PlanWriter::values())],
            'queries' => ['nullable', 'array', 'max:12'],
            'queries.*' => ['string', 'max:60'],
            'focusKeyphrase' => ['nullable', 'string', 'max:120'],
            'scene' => ['nullable', Rule::in(CoveScene::values())],
        ]);

        $this->assertMayEdit($request, $plan);

        if (isset($data['scene']) && ! in_array(CoveScene::from($data['scene']), CoveScene::forKind($plan->kind), true)) {
            $allowed = array_map(fn (CoveScene $s) => $s->value, CoveScene::forKind($plan->kind));

            throw ValidationException::withMessages([
                'scene' => $allowed === []
                    ? 'A '.$plan->kind->label().' carries no drawing, so it names no scene.'
                    : 'A '.$plan->kind->label().' cannot be drawn as `'.$data['scene'].'`. It takes one of: '
                        .implode(', ', $allowed).'.',
            ]);
        }

        if (isset($data['focusKeyphrase']) && ! $plan->kind->writesBody()) {
            throw ValidationException::withMessages([
                'focusKeyphrase' => 'A '.$plan->kind->label().' does not carry a focus keyphrase.',
            ]);
        }

        $plan->forceFill(array_filter([
            'title' => HouseStyle::plain($data['title'] ?? null),
            'blurb' => HouseStyle::plain($data['blurb'] ?? null),
            // Neither of these is ever rendered: one is a note to whoever reads
            // the plan, the other is direction for the writer. Stored as sent.
            'note' => $data['note'] ?? null,
            'build_instructions' => $data['buildInstructions'] ?? null,
            'pick_mode' => $data['pickMode'] ?? null,
            'writer' => $data['writer'] ?? null,
            'queries' => $data['queries'] ?? null,
            'focus_keyphrase' => $data['focusKeyphrase'] ?? null,
            'scene' => $data['scene'] ?? null,
        ], fn ($v) => $v !== null))->save();

        return response()->json(['data' => $this->payload($plan->refresh())]);
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
         * A Daily is addressed by its date; every other kind by its slug.
         *
         * Refused rather than reconciled, because both silent fixes are worse
         * than the error: dropping the date would publish something the author
         * scheduled, and keeping it would hand the morning build a guide.
         *
         * Asked of the enum rather than listed, because this used to name
         * Persona and every kind added since arrived here silently addressed as
         * a Daily — a guide POSTed with a slug was stored with a date and no
         * address at all, and nothing said so.
         */
        if ($kind->isDated()) {
            if ($slug !== null) {
                throw ValidationException::withMessages([
                    'slug' => 'A Daily Cove is addressed by its date. Its URL slug comes from the title when it is built.',
                ]);
            }
        } else {
            if ($date !== null) {
                throw ValidationException::withMessages([
                    'date' => 'A '.$kind->label().' has no date — it is permanent. Send a slug instead.',
                ]);
            }

            if ($slug === null) {
                throw ValidationException::withMessages([
                    'slug' => 'A '.$kind->label().' needs a slug: it is the permanent URL the page lives at.',
                ]);
            }
        }

        /*
         * The article fields belong to an article, and are refused elsewhere.
         *
         * Dropping them silently is the failure worth avoiding: an author who
         * sends a FAQ with a persona and gets a 200 has every reason to believe
         * it was stored, and finds out when the page renders without one.
         */
        if (! $kind->writesBody()) {
            $stray = array_keys(array_filter([
                'focusKeyphrase' => $data['focusKeyphrase'] ?? null,
                'metaDescription' => $data['metaDescription'] ?? null,
                'body' => $data['body'] ?? null,
                'faq' => $data['faq'] ?? null,
            ]));

            if ($stray !== []) {
                throw ValidationException::withMessages([
                    $stray[0] => 'A '.$kind->label().' does not carry '
                        .implode(', ', $stray).'. It is a column: its words go in `editorial`.',
                ]);
            }
        }

        /*
         * A scene has to be one this kind can mean.
         *
         * The two vocabularies share one column and do not overlap: a persona
         * names a kind of person, an article names a subject. So `customs` on a
         * persona is not a harmless spare field — it is a drawing of a parcel at
         * a border at the top of a page about somebody who likes coffee, and
         * nothing downstream would ever report it. Refused here for the same
         * reason the article fields above are: the write returns 200 either way,
         * and the author finds out when the page renders.
         *
         * A kind with no vocabulary — a Daily, a Shop Cove — refuses every
         * scene, which is `forKind()` returning an empty list rather than a
         * separate rule to keep in step.
         */
        $scene = $data['scene'] ?? null;

        if ($scene !== null && ! in_array(CoveScene::from($scene), CoveScene::forKind($kind), true)) {
            $allowed = array_map(fn (CoveScene $s) => $s->value, CoveScene::forKind($kind));

            throw ValidationException::withMessages([
                'scene' => $allowed === []
                    ? 'A '.$kind->label().' carries no drawing, so it names no scene.'
                    : 'A '.$kind->label().' cannot be drawn as `'.$scene.'`. It takes one of: '
                        .implode(', ', $allowed).'.',
            ]);
        }

        /*
         * A Shop Cove's slug has to name a shop this market actually compares.
         *
         * Every other rule about a slug is about shape — length, characters,
         * whether the namespace is free. This one is about meaning, and it is
         * the only kind whose address is not ours to choose: the slug is derived
         * from `merchants.domain`, which is what lets the same shop keep one
         * address in every market it trades in and be paired for hreflang.
         *
         * A hand-written or API-supplied slug bypasses that derivation, and the
         * result is an article about a shop absent from the directory it sits
         * above — with nothing anywhere to report it, because the plan is
         * perfectly well-formed.
         */
        if ($kind === CoveKind::Shop && $slug !== null && $this->shops->shopFor($market, $slug) === null) {
            throw ValidationException::withMessages([
                'slug' => "No shop in {$market->value} is addressed by '{$slug}'. A Shop Cove's slug comes from the "
                    ."shop's own domain — bol.com is `bol-com`, coolblue.be is `coolblue-be` — and the shop has to "
                    .'have active offers in this market or be a live source here.',
            ]);
        }

        /*
         * A season is a scheduling fact about a seasonal guide and nothing else.
         *
         * A window on a kind nothing reads it from would be a decision that
         * looks made and is not.
         */
        if ($kind !== CoveKind::Seasonal && (($data['seasonFrom'] ?? null) !== null || ($data['seasonTo'] ?? null) !== null)) {
            throw ValidationException::withMessages([
                'seasonFrom' => 'Only a seasonal guide carries a window. Send kind=seasonal, or leave it off.',
            ]);
        }

        $existing = match (true) {
            ! $kind->isDated() => CovePlan::query()
                ->where('market', $market->value)
                ->where('slug', $slug)
                ->first(),
            $date === null => null,
            default => CovePlan::query()
                ->where('market', $market->value)
                ->where('kind', CoveKind::Daily->value)
                ->whereDate('drop_date', $date)
                ->first(),
        };

        /*
         * One slug namespace per market, across every dateless kind.
         *
         * So the row this found may be a persona when a guide was asked for, and
         * upserting it would silently change what an existing page *is* — its
         * URL space, its layout and its product floor all at once.
         */
        if ($existing !== null && $existing->kind !== $kind) {
            throw ValidationException::withMessages([
                'slug' => "That slug is already a {$existing->kind->label()} in {$market->value}. "
                    .'One slug namespace covers every kind in a market, so pick another.',
            ]);
        }

        if ($existing !== null) {
            $this->assertMayEdit($request, $existing);
        }

        $items = $this->resolveItems($market, $data);
        $curated = $items->pluck('group')->filter()->values();

        $attributes = [
            'market' => $market->value,
            'kind' => $kind->value,
            'drop_date' => $kind->isDated() ? $date : null,
            'slug' => $kind->isDated() ? null : $slug,
            'pick_mode' => $data['pickMode'] ?? PickMode::Open->value,
            // The drawing. Checked against the kind's own vocabulary above.
            'scene' => $scene,
            /*
             * House style on everything a reader sees. `prose` where the field
             * is rendered by CoveMarkup, `plain` where it is printed as a text
             * node — see {@see HouseStyle} for why the two differ over `**`.
             *
             * `buildInstructions` and `note` are deliberately untouched: they
             * are an editor talking to the builder, not copy, and neither is
             * ever rendered.
             */
            'title' => HouseStyle::plain($data['title']),
            'blurb' => HouseStyle::plain($data['blurb'] ?? null),
            'editorial' => HouseStyle::prose($data['editorial'] ?? null),

            /*
             * Sending prose means you wrote it.
             *
             * The builder used to infer this from whichever fields came back
             * filled, and every key deployed against that behaviour depends on
             * it: a client that POSTs a guide with a `body` expects the model
             * not to rewrite it. Defaulting here keeps that contract exactly
             * while making it a stored fact rather than a guess, and an author
             * who wants the model to finish a draft they started says so.
             */
            'writer' => $data['writer']
                ?? (filled($data['editorial'] ?? null) || filled($data['body'] ?? null)
                    ? PlanWriter::Authored->value
                    : PlanWriter::Builder->value),
            'build_instructions' => $data['buildInstructions'] ?? null,
            'queries' => $data['queries'] ?? [],
            'note' => $data['note'] ?? null,

            /*
             * The parts of an article that are decided before it is written.
             *
             * Left empty the builder invents them, which is what a guide always
             * did and the reason its keyphrase and FAQ were nobody's decision.
             * Sent here they survive every rebuild — that is the whole contract.
             */
            ...($kind->writesBody() ? [
                'focus_keyphrase' => $data['focusKeyphrase'] ?? null,
                'meta_description' => HouseStyle::plain($data['metaDescription'] ?? null),
                'body' => HouseStyle::prose($data['body'] ?? null),
                // Stored as the two-letter shape the page and the schema.org
                // renderer read. The API spells them out, because `q`/`a` in a
                // JSON body is a guess.
                'faq' => isset($data['faq'])
                    ? array_map(fn (array $pair) => [
                        'q' => HouseStyle::plain($pair['question']),
                        'a' => HouseStyle::prose($pair['answer']),
                    ], $data['faq'])
                    : null,
            ] : []),

            ...($kind === CoveKind::Seasonal ? [
                'season_from' => $data['seasonFrom'] ?? null,
                'season_to' => $data['seasonTo'] ?? null,
            ] : []),
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
        $queued = $request->boolean('build') && $this->isBuildable($plan);

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
        if (! $this->isBuildable($plan)) {
            throw ValidationException::withMessages([
                'plan' => $plan->kind->isDated()
                    ? 'That Daily Cove has no date. Give it one, or make it a permanent kind with a slug.'
                    : 'That '.$plan->kind->label().' has no slug, and a permanent page is addressed by one.',
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
            // A Daily is read back through the API, which reports what the
            // builder actually managed to put on it; everything else is a
            // permanent page and its own URL is the honest answer.
            'readBack' => $plan->kind->isDated()
                ? "/api/editorial/editions/{$plan->market->value}/{$plan->drop_date->toDateString()}"
                : '/'.$plan->market->value.'/'.$plan->kind->path((string) $plan->slug, $plan->market),
        ], 202);
    }

    /**
     * Is there an address to build this plan at?
     *
     * A Daily needs its date and everything else needs its slug. Asked in one
     * place because it is asked three times — approve-and-build, build, and the
     * error message that explains the refusal — and three copies of a rule that
     * grows an arm per kind is how the persona-only version survived four new
     * kinds without anyone noticing.
     */
    private function isBuildable(CovePlan $plan): bool
    {
        return $plan->kind->isDated()
            ? $plan->drop_date !== null
            : filled($plan->slug);
    }

    /**
     * Dispatch the right build for this plan's kind.
     *
     * A Daily goes through `BuildDailyEdition` because that job also does two
     * things about the *day* — mining yesterday's searches for topics and
     * seeding the seasonal ones — which nothing else wants. Every other kind
     * goes to `BuildCove`, which reads the kind off the plan; naming them
     * individually here is what left guides unbuildable through this API while
     * the endpoint answered 202.
     */
    private function queueBuild(CovePlan $plan): void
    {
        $plan->kind->isDated()
            ? BuildDailyEdition::dispatch($plan->market, $plan->drop_date->toDateString())
            : BuildCove::dispatch($plan->id);
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
            'scene' => ['nullable', Rule::in(CoveScene::values())],

            /*
             * Who writes the prose. Defaults from whether prose was sent — see
             * the attribute list — so this only has to be stated to go against
             * that: `builder` on a plan carrying a first draft somebody wants
             * the model to finish.
             */
            'writer' => ['nullable', Rule::in(PlanWriter::values())],

            /*
             * Article fields. Optional every one of them: an empty field is the
             * builder's to write, a filled one is the author's and is never
             * overwritten.
             */
            'focusKeyphrase' => ['nullable', 'string', 'max:120'],
            'metaDescription' => ['nullable', 'string', 'max:160'],
            'body' => ['nullable', 'string', 'max:20000'],
            'faq' => ['nullable', 'array', 'max:10'],
            // Both halves or neither. A question with no answer renders as a
            // broken FAQPage and Google says so out loud.
            'faq.*.question' => ['required', 'string', 'max:200'],
            'faq.*.answer' => ['required', 'string', 'max:600'],

            /*
             * MM-DD and year-less, because the window recurs every year. An end
             * before its start wraps the year, which is how Valentine's opens on
             * 12-27.
             */
            'seasonFrom' => ['nullable', 'string', 'regex:/^\d{2}-\d{2}$/'],
            'seasonTo' => ['nullable', 'string', 'regex:/^\d{2}-\d{2}$/'],

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
                // `note` is a brief for the builder; `verdict` is printed on
                // the card, so only one of the two gets house style.
                'note' => $item['note'] ?? null,
                'verdict' => HouseStyle::plain($item['verdict'] ?? null),
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
            'scene' => $plan->scene?->value,
            'title' => $plan->title,
            'status' => $plan->status,
            // Who writes the prose, and therefore whether a build will call a
            // model at all. See App\Enums\PlanWriter.
            'writer' => $plan->writer->value,

            /*
             * Where this plan has got to, and what to do to it next.
             *
             * Derived rather than stored — a stored state goes stale the moment
             * somebody curates from another screen — and read from the same
             * service the planner's tab strip reads, so the API and the panel
             * cannot disagree about what "needs writing" means.
             */
            'state' => PlanState::of($plan)->value,
            'nextStage' => PlanState::of($plan)->nextStage(),

            /*
             * Why the last build produced no page, when it produced none.
             *
             * This used to be a log line at six in the morning: an approved plan
             * whose catalogue had gone thin was indistinguishable from one whose
             * date had not arrived yet, on every screen.
             */
            'lastBuild' => $plan->last_build_failed_at === null ? null : [
                'failedAt' => $plan->last_build_failed_at->toIso8601String(),
                'why' => $plan->last_build_note,
            ],
            'curatedCount' => $plan->items()->count(),
            'hasEditorial' => filled($plan->editorial),
            'edition' => $plan->edition === null ? null : [
                'id' => $plan->edition->id,
                'status' => $plan->edition->status->value,
                // A persona lives at its slug and has no date to build a URL
                // from — the same split as the routes.
                // Off the kind, not off the date. Four of the six kinds are
                // dateless and only one of them is a persona, so a null date
                // used to send a guide's URL into /gift-ideas.
                'url' => '/'.$plan->market->value.'/'.$plan->kind->path((string) $plan->edition->slug, $plan->market),
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function payload(CovePlan $plan): array
    {
        return [
            ...$this->summary($plan),

            /*
             * The concurrency token, same one `POST /coves/{id}/editorial`
             * demands.
             *
             * It was only ever handed out by `GET /coves/queue`, and that
             * endpoint excludes anything that already has prose — so a plan
             * could be written once and then never revised through the narrow
             * endpoint, because there was nowhere left to obtain a revision for
             * it. The only route back was the whole-plan upsert, which replaces
             * the shortlist.
             */
            'revision' => PlanRevision::of($plan),

            'blurb' => $plan->blurb,
            'editorial' => $plan->editorial,
            'buildInstructions' => $plan->build_instructions,
            'queries' => $plan->queries,
            'note' => $plan->note,

            /*
             * Read back so a writer can see what it has already decided.
             *
             * Only for the kinds that carry them — a persona reading back a null
             * `faq` invites an agent to fill it in, and it would be refused.
             */
            ...($plan->kind->writesBody() ? [
                'focusKeyphrase' => $plan->focus_keyphrase,
                'metaDescription' => $plan->meta_description,
                'body' => $plan->body,
                'faq' => $plan->faq,
            ] : []),

            ...($plan->kind === CoveKind::Seasonal ? [
                'season' => ['from' => $plan->season_from, 'to' => $plan->season_to],
                /*
                 * Which part of the season this is.
                 *
                 * Null on a season the catalogue could only fill one subject of
                 * — that is a page rather than a series, and it says so by
                 * carrying no number anywhere. An author reading this back needs
                 * it: "part 2" is a fact about what the piece may assume the
                 * reader has already seen. See docs/features/seasonal-series.md.
                 */
                'series' => $plan->series_key === null ? null : [
                    'key' => $plan->series_key,
                    'part' => $plan->part,
                ],
            ] : []),

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
             *
             * Dailies only, not merely "anything with a date". A seasonal part
             * carries a due date now, and reporting the day's rotation theme
             * against it would answer a question nobody asked — that part is not
             * competing for the day and overrides nothing.
             */
            'calendarTheme' => $plan->kind->isDated() && $plan->drop_date !== null
                ? $this->calendarTheme($plan)
                : null,
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
