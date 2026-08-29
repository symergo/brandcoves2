<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Enums\CoveKind;
use App\Enums\Market;
use App\Http\Controllers\Controller;
use App\Models\CovePlan;
use App\Models\CovePlanItem;
use App\Services\Editorial\Allowlist;
use App\Services\Editorial\LinkCheck;
use App\Services\Editorial\ProductLookup;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * The writing queue: what needs prose, and where the prose comes back.
 *
 * Built for a scheduled agent rather than a person at a terminal. The existing
 * endpoints can already do this — list plans, read one, upsert it — but only in
 * a shape that makes a writer guess: filter the index correctly, fetch each plan,
 * assemble the link allowlist by trial and error, and post the whole plan back to
 * change one paragraph.
 *
 * Two endpoints and one guard fix that.
 *
 * ## Why it costs nothing to write a Cove this way
 *
 * Authored prose short-circuits the model completely — `EditionBuilder` records
 * `editorial_source: 'planned'` and never calls `AiClient`. A Cove written
 * through here is not subject to the daily cap and spends nothing on this
 * server, which is the whole reason the queue is worth having.
 *
 * ## What it deliberately cannot do
 *
 * Publish. A written plan stays a draft, and approving one needs the `publish`
 * ability that a writing key is not given. An agent writes; a person approves.
 * See docs/features/scheduled-writing.md.
 */
class CoveQueueController extends Controller
{
    public function __construct(
        private readonly ProductLookup $lookup,
        private readonly LinkCheck $links,
        private readonly Allowlist $allowlist,
    ) {}

    /**
     * The Coves that need writing, with everything needed to write them.
     *
     * One call rather than an index plus a fetch per plan, because the shape of
     * the loop is the thing being designed: a scheduled run should be able to
     * ask what to do and then do it.
     */
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'market' => ['nullable', Rule::in(Market::values())],
            'kinds' => ['nullable', 'array'],
            'kinds.*' => [Rule::in(CoveKind::values())],
            'limit' => ['nullable', 'integer', 'min:1', 'max:20'],
            'horizon' => ['nullable', 'integer', 'min:1', 'max:365'],
        ]);

        $plans = CovePlan::query()
            ->with(['items.group'])
            ->when(isset($data['market']), fn ($q) => $q->where('market', $data['market']))
            ->when(isset($data['kinds']), fn ($q) => $q->whereIn('kind', $data['kinds']))
            /*
             * Nothing that already has prose.
             *
             * This is what stops the same Cove being handed out on every run
             * without needing a "claimed" status that a crashed agent would
             * leave set forever.
             */
            ->where(fn ($q) => $q->whereNull('editorial')->orWhere('editorial', ''))
            // A draft or an approved plan both want writing; a used or rejected
            // one does not.
            ->whereIn('status', ['draft', 'approved'])
            ->when(
                isset($data['horizon']),
                fn ($q) => $q->where(fn ($w) => $w
                    ->whereNull('drop_date')
                    ->orWhereDate('drop_date', '<=', now()->addDays((int) $data['horizon']))),
            )
            // Soonest first, undated last: a dated Cove has a deadline and an
            // undated one does not.
            ->orderByRaw('drop_date is null')
            ->orderBy('drop_date')
            ->limit((int) ($data['limit'] ?? 5))
            ->get();

        return response()->json([
            'count' => $plans->count(),
            'data' => $plans->map(fn (CovePlan $plan) => $this->brief($plan))->all(),
        ]);
    }

    /**
     * Prose back, and nothing else.
     *
     * `POST /coves` is a full upsert whose `items` are replace-never-merge, and
     * when `items` is omitted it falls back to the legacy `pinnedGroupIds` — so
     * an agent submitting only prose there can empty a curated shortlist. That is
     * precisely the failure a scheduled writer would produce at 03:00 and nobody
     * would see until the page built.
     *
     * This writes words. It cannot add, remove or reorder a product.
     */
    public function store(Request $request, CovePlan $plan): JsonResponse
    {
        $data = $request->validate([
            'revision' => ['required', 'string'],
            'title' => ['nullable', 'string', 'max:120'],
            'blurb' => ['nullable', 'string', 'max:300'],
            'editorial' => ['nullable', 'string', 'max:'.config('giftcoves.editorial_api.max_editorial_chars')],
            'body' => ['nullable', 'string', 'max:20000'],
            'metaDescription' => ['nullable', 'string', 'max:160'],
            'faq' => ['nullable', 'array', 'max:10'],
            'faq.*.question' => ['required', 'string', 'max:200'],
            'faq.*.answer' => ['required', 'string', 'max:600'],
            'items' => ['nullable', 'array', 'max:24'],
            'items.*.id' => ['required', 'integer'],
            'items.*.copy' => ['nullable', 'string', 'max:500'],
            'items.*.verdict' => ['nullable', 'string', 'max:80'],
        ]);

        if ($data['revision'] !== $this->revision($plan)) {
            /*
             * Somebody changed this plan while it was being written.
             *
             * 409 with the current state rather than a silent overwrite: two
             * overlapping runs, or a person curating while an agent writes, and
             * the second writer would otherwise win by arriving later.
             */
            return response()->json([
                'message' => 'This plan changed while you were writing. Re-read it and start again.',
                'data' => $this->brief($plan->fresh(['items.group'])),
            ], 409);
        }

        $items = collect($data['items'] ?? [])->keyBy('id');
        $mine = $plan->items()->pluck('id')->all();
        $strangers = array_diff($items->keys()->all(), $mine);

        if ($strangers !== []) {
            // A 422 rather than a quiet skip: an id from another plan means the
            // writer is working from a stale brief, and the rest of what it
            // wrote is suspect too.
            throw ValidationException::withMessages([
                'items' => 'Not items on this plan: '.implode(', ', $strangers).'.',
            ]);
        }

        DB::transaction(function () use ($plan, $data, $items): void {
            $plan->forceFill(array_filter([
                'title' => $data['title'] ?? null,
                'blurb' => $data['blurb'] ?? null,
                'editorial' => $data['editorial'] ?? null,
                'body' => $data['body'] ?? null,
                'meta_description' => $data['metaDescription'] ?? null,
                'faq' => isset($data['faq'])
                    ? array_map(fn (array $p) => ['q' => $p['question'], 'a' => $p['answer']], $data['faq'])
                    : null,
            ], fn ($v) => $v !== null))->save();

            foreach ($items as $id => $item) {
                // Membership and rank are untouched. Only what is *said* about a
                // product can be written here.
                CovePlanItem::query()->whereKey($id)->update(array_filter([
                    'note' => $item['copy'] ?? null,
                    'verdict' => $item['verdict'] ?? null,
                ], fn ($v) => $v !== null));
            }
        });

        $plan->refresh()->load('items.group');

        return response()->json([
            'data' => $this->brief($plan),
            /*
             * Every field that carries prose, checked in one pass.
             *
             * The reason the queue hands out an allowlist in the first place: a
             * writer that guesses tokens fails this check and burns a round trip
             * on every single Cove.
             */
            'linkCheck' => $this->links->all(
                [
                    $plan->editorial,
                    $plan->body,
                    $plan->blurb,
                    ...array_map(fn (array $pair) => $pair['a'] ?? null, (array) $plan->faq),
                ],
                $plan->market,
                $plan->items->map(fn (CovePlanItem $item) => $item->group)->filter(),
                extraSearches: (array) $plan->queries,
            ),
        ]);
    }

    /**
     * Everything needed to write one Cove, without a second call.
     *
     * @return array<string, mixed>
     */
    private function brief(CovePlan $plan): array
    {
        $groups = $plan->items->map(fn (CovePlanItem $item) => $item->group)->filter();

        return [
            'id' => $plan->id,
            'revision' => $this->revision($plan),
            'kind' => $plan->kind->value,
            'market' => $plan->market->value,
            'language' => $plan->market->language(),
            'date' => $plan->drop_date?->toDateString(),
            'slug' => $plan->slug,
            'title' => $plan->title,
            'blurb' => $plan->blurb,
            'focusKeyphrase' => $plan->focus_keyphrase,
            'queries' => $plan->queries ?? [],
            'buildInstructions' => $plan->build_instructions,
            'body' => $plan->body,
            'faq' => $plan->faq,

            'items' => $plan->items->map(fn (CovePlanItem $item) => [
                'id' => $item->id,
                'rank' => $item->rank,
                // The reason a person put this product on the list, and the most
                // useful sentence a writer gets.
                'note' => $item->note,
                'verdict' => $item->verdict,
                'product' => $item->group === null ? null : $this->lookup->describe($item->group),
            ])->values()->all(),

            /*
             * What this Cove's prose may link to.
             *
             * Handed over rather than left to be discovered, because the
             * alternative is a writer inventing `/gifts` in the middle of a
             * paragraph and finding out from `linkCheck` afterwards.
             */
            'allowlist' => $this->allowlist->full(
                $groups,
                $plan->market,
                extraSearches: (array) $plan->queries,
            ),
        ];
    }

    /**
     * What this plan looked like when it was handed out.
     *
     * A scheduled agent retries, and two runs must not overwrite each other or a
     * person's edit. Covers the plan's own timestamp and its item ids, because
     * both "somebody rewrote the brief" and "somebody removed a product" make
     * whatever is being written about it stale.
     */
    private function revision(CovePlan $plan): string
    {
        return substr(hash('sha256', implode('|', [
            $plan->updated_at?->toIso8601String() ?? '',
            $plan->items()->orderBy('id')->pluck('id')->implode(','),
        ])), 0, 16);
    }
}
