<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Enums\Market;
use App\Enums\PublishStatus;
use App\Http\Controllers\Controller;
use App\Models\Guide;
use App\Models\GuideItem;
use App\Models\ProductGroup;
use App\Services\Editorial\ProductLookup;
use App\Services\Guides\CoveMarkup;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Buying guides, written from outside.
 *
 * The evergreen half of the product-inspiration surface: a Cove is read on the
 * day and a guide is read for years, which is why they publish on separate
 * clocks. `/{market}/guides/{slug}` is a permanent, indexable URL, so this is
 * the endpoint that grows the corpus.
 *
 * The shortlist is the substance and the prose is presentation — the same
 * principle GuideBuilder works to. Which is why `items` is required and the
 * copy is not: a guide with seven real, in-stock, comparable products and no
 * commentary is still useful, and a guide with commentary and no products is
 * not a guide.
 */
class GuideEditorialController extends Controller
{
    /**
     * Long enough to be a real comparison.
     *
     * GuideBuilder refuses to publish under five and targets seven. Three is
     * the floor here because a hand-written "the two worth buying" is a
     * legitimate piece and an automated shortlist of two is a thin page — the
     * difference being that a human chose it.
     */
    private const MIN_ITEMS = 3;

    private const MAX_ITEMS = 12;

    public function __construct(private readonly ProductLookup $lookup) {}

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'market' => ['nullable', Rule::in(Market::values())],
            'status' => ['nullable', Rule::in(PublishStatus::values())],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $guides = Guide::query()
            ->withCount('items')
            ->when(isset($data['market']), fn ($q) => $q->where('market', $data['market']))
            ->when(isset($data['status']), fn ($q) => $q->where('status', $data['status']))
            ->orderByDesc('id')
            ->limit((int) ($data['limit'] ?? 50))
            ->get();

        return response()->json([
            'count' => $guides->count(),
            'data' => $guides->map(fn (Guide $g) => [
                'id' => $g->id,
                'market' => $g->market->value,
                'slug' => $g->slug,
                'title' => $g->title,
                'status' => $g->status->value,
                'itemCount' => $g->items_count,
                'publishedAt' => $g->published_at?->toIso8601String(),
                'url' => '/'.$g->market->value.'/guides/'.$g->slug,
            ])->all(),
        ]);
    }

    public function show(Guide $guide): JsonResponse
    {
        $guide->load('items.group');

        return response()->json(['data' => $this->payload($guide)]);
    }

    /**
     * Write or rewrite a guide.
     *
     * Keyed on (market, slug), which is the table's own unique key, so a retry
     * after a timeout updates rather than colliding. Items are rebuilt
     * wholesale rather than diffed for the same reason GuideBuilder does it:
     * ranks are positional, and a partial update leaves a guide whose #3 is
     * missing.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        $market = Market::from($data['market']);

        $slug = Str::slug($data['slug'] ?? $data['title']);

        if ($slug === '') {
            throw ValidationException::withMessages([
                'slug' => 'That title produces an empty slug. Give an explicit slug.',
            ]);
        }

        $groupIds = array_map(fn (array $item) => (int) $item['groupId'], $data['items']);

        if (count(array_unique($groupIds)) !== count($groupIds)) {
            throw ValidationException::withMessages([
                'items' => 'The same product appears twice. A comparison of a thing against itself is not a comparison.',
            ]);
        }

        $rejected = $this->lookup->rejectUnusable($market, $groupIds);

        if ($rejected !== []) {
            throw ValidationException::withMessages([
                'items' => 'Not usable in '.$market->value.': '.implode(', ', $rejected).
                    '. Every item must exist in this market, be in stock, priced and have an image.',
            ]);
        }

        $this->assertNoLinkTokens($data);

        $existing = Guide::query()
            ->where('market', $market->value)
            ->where('slug', $slug)
            ->first();

        if ($existing !== null && $existing->status === PublishStatus::Published) {
            /*
             * Rewriting a live guide is allowed — guides are meant to be kept
             * current, and refusing would make the API useless for the thing
             * guides most need. It stays published; only the contents change.
             */
            $status = PublishStatus::Published;
        } else {
            $status = PublishStatus::Draft;
        }

        $guide = DB::transaction(function () use ($market, $slug, $data, $status): Guide {
            $guide = Guide::updateOrCreate(
                ['market' => $market->value, 'slug' => $slug],
                [
                    'title' => $data['title'],
                    'intro' => $data['intro'] ?? null,
                    'body_md' => $data['bodyMd'] ?? null,
                    'source_queries' => $data['sourceQueries'] ?? [],
                    'source_volume' => (int) ($data['sourceVolume'] ?? 0),
                    // Falls back to the intro, trimmed to a length a search
                    // result will actually show rather than truncate mid-word.
                    'meta_description' => $data['metaDescription']
                        ?? Str::limit((string) ($data['intro'] ?? ''), 155, '') ?: null,
                    'focus_keyphrase' => $data['focusKeyphrase'] ?? null,
                    'faq' => $this->faq($data['faq'] ?? []),
                    'status' => $status->value,
                    // Rewriting a guide is exactly what the freshness check
                    // wants to know about: the copy has just been looked at.
                    'last_checked_at' => now(),
                ],
            );

            $guide->items()->delete();

            foreach ($data['items'] as $rank => $item) {
                GuideItem::create([
                    'guide_id' => $guide->id,
                    'group_id' => (int) $item['groupId'],
                    // Rank is array order. Position is the argument a "best of"
                    // is making, so it is the author's to decide.
                    'rank' => $rank + 1,
                    'editorial_copy' => $item['copy'] ?? null,
                    'verdict' => $item['verdict'] ?? null,
                    'unavailable' => false,
                ]);
            }

            return $guide;
        });

        $guide->load('items.group');

        return response()->json(
            ['data' => $this->payload($guide)],
            $existing === null ? 201 : 200,
        );
    }

    /** Publish a guide, or unpublish one that should not be live. */
    public function publish(Request $request, Guide $guide): JsonResponse
    {
        $publish = ! $request->boolean('unpublish');

        if ($publish && $guide->items()->count() < self::MIN_ITEMS) {
            throw ValidationException::withMessages([
                'guide' => 'A guide needs at least '.self::MIN_ITEMS.' items before it is worth a reader\'s time.',
            ]);
        }

        $guide->update([
            'status' => $publish ? PublishStatus::Published->value : PublishStatus::Draft->value,
            // Set once, on first publish. Overwriting it on every republish
            // would make an updated guide look brand new to a crawler, which is
            // a claim about the page that is not true.
            'published_at' => $publish ? ($guide->published_at ?? now()) : null,
        ]);

        return response()->json([
            'data' => $this->payload($guide->refresh()->load('items.group')),
        ]);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        return $request->validate([
            'market' => ['required', Rule::in(Market::values())],
            'title' => ['required', 'string', 'max:160'],

            // Derived from the title when absent. Explicit when the title is
            // long or seasonal and the URL should outlive it.
            'slug' => ['nullable', 'string', 'max:160'],

            'intro' => ['nullable', 'string', 'max:2000'],
            'bodyMd' => ['nullable', 'string', 'max:20000'],
            'metaDescription' => ['nullable', 'string', 'max:160'],
            'focusKeyphrase' => ['nullable', 'string', 'max:120'],

            /*
             * The queries this guide answers.
             *
             * Recorded because "we wrote this because 240 people searched for it
             * here" is both the honest reason the page exists and a fact no
             * competitor can copy. Optional for a hand-written guide, which may
             * be answering something the log has not seen yet.
             */
            'sourceQueries' => ['nullable', 'array', 'max:20'],
            'sourceQueries.*' => ['string', 'max:120'],
            'sourceVolume' => ['nullable', 'integer', 'min:0'],

            'faq' => ['nullable', 'array', 'max:10'],
            'faq.*.question' => ['required', 'string', 'max:300'],
            'faq.*.answer' => ['required', 'string', 'max:1200'],

            'items' => ['required', 'array', 'min:'.self::MIN_ITEMS, 'max:'.self::MAX_ITEMS],
            'items.*.groupId' => ['required', 'integer'],
            // A short "best for X" label. The most-read words on the page.
            'items.*.verdict' => ['nullable', 'string', 'max:120'],
            'items.*.copy' => ['nullable', 'string', 'max:1200'],
        ]);
    }

    /**
     * Guides render as plain text, so a link token would be printed, not linked.
     *
     * Rejected rather than stripped: an author who wrote `[[product:12]]` meant
     * to link, and silently deleting it produces a sentence with a hole in it
     * that nobody will notice until it is indexed. Every item already links to
     * its own product page, which is what the tokens would have been for.
     *
     * @param  array<string, mixed>  $data
     */
    private function assertNoLinkTokens(array $data): void
    {
        $fields = ['intro' => $data['intro'] ?? null, 'bodyMd' => $data['bodyMd'] ?? null];

        foreach ($data['items'] as $i => $item) {
            $fields["items.{$i}.copy"] = $item['copy'] ?? null;
        }

        $offenders = array_keys(array_filter(
            $fields,
            fn (?string $text) => CoveMarkup::containsTokens($text),
        ));

        if ($offenders !== []) {
            throw ValidationException::withMessages([
                'items' => 'Link tokens are not rendered in guides and would be printed literally: '.
                    implode(', ', $offenders).'. Guide items already link to their product pages; '.
                    'write plain prose here. Tokens belong in a Cove editorial.',
            ]);
        }
    }

    /**
     * @param  list<array{question: string, answer: string}>  $faq
     * @return list<array{q: string, a: string}>
     */
    private function faq(array $faq): array
    {
        // Stored as q/a because that is what StructuredData::faq() reads and
        // what the existing rows hold. Accepted as question/answer because that
        // is what anyone writing one would send.
        return array_values(array_map(fn (array $pair) => [
            'q' => $pair['question'],
            'a' => $pair['answer'],
        ], $faq));
    }

    /** @return array<string, mixed> */
    private function payload(Guide $guide): array
    {
        return [
            'id' => $guide->id,
            'market' => $guide->market->value,
            'slug' => $guide->slug,
            'title' => $guide->title,
            'intro' => $guide->intro,
            'bodyMd' => $guide->body_md,
            'metaDescription' => $guide->meta_description,
            'focusKeyphrase' => $guide->focus_keyphrase,
            'faq' => $guide->faq,
            'sourceQueries' => $guide->source_queries,
            'sourceVolume' => $guide->source_volume,
            'status' => $guide->status->value,
            'publishedAt' => $guide->published_at?->toIso8601String(),
            'url' => '/'.$guide->market->value.'/guides/'.$guide->slug,
            'items' => $guide->items
                ->map(fn (GuideItem $item) => [
                    'rank' => $item->rank,
                    'verdict' => $item->verdict,
                    'copy' => $item->editorial_copy,
                    'product' => $item->group instanceof ProductGroup
                        ? $this->lookup->describe($item->group)
                        : null,
                ])
                ->all(),
        ];
    }
}
