<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Enums\CoveKind;
use App\Enums\GuideKind;
use App\Enums\Market;
use App\Enums\PublishStatus;
use App\Http\Controllers\Controller;
use App\Models\DailyPick;
use App\Models\DailyPickSet;
use App\Models\ProductGroup;
use App\Services\Editorial\LinkCheck;
use App\Services\Editorial\ProductLookup;
use App\Services\Guides\CoveMarkup;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Guides and advice articles, written from outside.
 *
 * The evergreen half of the product-inspiration surface: a Cove is read on the
 * day and a guide is read for years, which is why they publish on separate
 * clocks. `/{market}/guides/{slug}` is a permanent, indexable URL, so this is
 * the endpoint that grows the corpus.
 *
 * ## Two kinds, one endpoint
 *
 * For a **buying** guide the shortlist is the substance and the prose is
 * presentation — the same principle GuideBuilder works to. Seven real, in-stock,
 * comparable products with no commentary is still useful; commentary with no
 * products is not a buying guide.
 *
 * An **advice** article inverts that. "How to tell a paid review from a real
 * one" has no shortlist and demanding one would either block the piece or pad it
 * with products the writing is not about. See {@see GuideKind}.
 *
 * ## Prose carries links
 *
 * Both kinds render through CoveMarkup, so copy may link to products, brands,
 * searches, **other guides** and the site's own pages by token. That last pair
 * is what stops an article being a leaf: advice earns its place by pointing at
 * the guide for the thing the reader was about to buy.
 */
class GuideEditorialController extends Controller
{
    private const MAX_ITEMS = 12;

    public function __construct(
        private readonly ProductLookup $lookup,
        private readonly LinkCheck $links,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'market' => ['nullable', Rule::in(Market::values())],
            'status' => ['nullable', Rule::in(PublishStatus::values())],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $guides = DailyPickSet::query()
            ->articles()
            ->withCount('picks')
            ->when(isset($data['market']), fn ($q) => $q->where('market', $data['market']))
            ->when(isset($data['status']), fn ($q) => $q->where('status', $data['status']))
            ->orderByDesc('id')
            ->limit((int) ($data['limit'] ?? 50))
            ->get();

        return response()->json([
            'count' => $guides->count(),
            'data' => $guides->map(fn (DailyPickSet $g) => [
                'id' => $g->id,
                'market' => $g->market->value,
                'slug' => $g->slug,
                'title' => $g->theme_title,
                'kind' => self::apiKind($g),
                'status' => $g->status->value,
                'itemCount' => $g->picks_count,
                'publishedAt' => $g->published_at?->toIso8601String(),
                'url' => '/'.$g->market->value.'/guides/'.$g->slug,
            ])->all(),
        ]);
    }

    public function show(DailyPickSet $guide): JsonResponse
    {
        $guide->load('picks.group');

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

        /*
         * Two vocabularies, deliberately.
         *
         * GuideKind is what this API speaks and what decides how many products
         * a *human-authored* piece needs — three for a buying guide, none for
         * advice. CoveKind is what the table stores, and its floor of five
         * governs pieces the machine generates. Folding the two would silently
         * raise the API's floor to five and start rejecting deliberate
         * three-product comparisons that have always been allowed.
         */
        $requested = GuideKind::from($data['kind'] ?? GuideKind::Buying->value);
        $kind = $requested === GuideKind::Advice ? CoveKind::Advice : CoveKind::Guide;
        $items = $data['items'] ?? [];

        if (count($items) < $requested->minimumItems()) {
            throw ValidationException::withMessages([
                'items' => "A {$requested->value} guide needs at least {$requested->minimumItems()} products. ".
                    'Writing about how to shop rather than about what to buy? Send kind: "advice", which needs none.',
            ]);
        }

        $groupIds = array_map(fn (array $item) => (int) $item['groupId'], $items);

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

        $existing = DailyPickSet::query()
            ->articles()
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

        $guide = DB::transaction(function () use ($market, $slug, $data, $status, $kind, $items): DailyPickSet {
            $guide = DailyPickSet::updateOrCreate(
                [
                    'market' => $market->value,
                    /*
                     * The kind is part of the key, not just a column.
                     *
                     * (market, slug) is unique across every dateless kind, so
                     * without it a POST could silently overwrite a persona that
                     * happened to hold this slug — turning a gift page into a
                     * buying guide with no error anywhere.
                     */
                    'kind' => $kind->value,
                    'slug' => $slug,
                ],
                [
                    'theme_title' => $data['title'],
                    'theme_slug' => $slug,
                    'theme_source' => 'planned',
                    'theme_blurb' => $data['intro'] ?? null,
                    'body' => $data['bodyMd'] ?? null,
                    'source_queries' => $data['sourceQueries'] ?? [],
                    'source_volume' => (int) ($data['sourceVolume'] ?? 0),
                    /*
                     * Falls back to the intro, trimmed to a length a search
                     * result will actually show rather than truncate mid-word.
                     *
                     * Through plain() first: the intro carries link tokens, and
                     * a description reading "see [[page:search]]" is what a
                     * searcher would be shown in the result.
                     */
                    'meta_description' => $data['metaDescription']
                        ?? Str::limit(
                            app(CoveMarkup::class)->plain($data['intro'] ?? ''),
                            155,
                            '',
                        ) ?: null,
                    'focus_keyphrase' => $data['focusKeyphrase'] ?? null,
                    'faq' => $this->faq($data['faq'] ?? []),
                    'status' => $status->value,
                    // Rewriting a guide is exactly what the freshness check
                    // wants to know about: the copy has just been looked at.
                    'last_checked_at' => now(),
                ],
            );

            $guide->picks()->delete();

            foreach ($items as $rank => $item) {
                $group = ProductGroup::query()->find((int) $item['groupId']);

                DailyPick::create([
                    'set_id' => $guide->id,
                    'group_id' => $group->id,
                    // Rank is array order. Position is the argument a "best of"
                    // is making, so it is the author's to decide.
                    'rank' => $rank + 1,
                    // A permanent per-pick slug, as every other pick has. The
                    // old `guide_items` had none, which is why this is derived
                    // here rather than carried across.
                    'slug' => Str::slug($group->title).'-'.$group->id,
                    'blurb' => $item['copy'] ?? null,
                    'verdict' => $item['verdict'] ?? null,
                    'unavailable' => false,
                ]);
            }

            return $guide;
        });

        $guide->load('picks.group');

        return response()->json(
            [
                'data' => $this->payload($guide),
                /*
                 * Every field that carries prose, checked in one pass against
                 * the guide's real allowlist.
                 *
                 * Unlike a Cove plan this is authoritative: a guide's items are
                 * exactly what the author supplied, so nothing gets added later
                 * that could rescue a token reported here as unresolved.
                 */
                'linkCheck' => $this->links->all(
                    [
                        $guide->theme_blurb,
                        $guide->body,
                        ...array_map(fn (array $pair) => $pair['a'] ?? null, (array) $guide->faq),
                        ...$guide->picks->pluck('blurb')->all(),
                    ],
                    $market,
                    $guide->picks->map(fn (DailyPick $pick) => $pick->group)->filter(),
                    excludeGuideId: $guide->id,
                    extraSearches: (array) $guide->source_queries,
                ),
            ],
            $existing === null ? 201 : 200,
        );
    }

    /** Publish a guide, or unpublish one that should not be live. */
    public function publish(Request $request, DailyPickSet $guide): JsonResponse
    {
        $publish = ! $request->boolean('unpublish');

        // The authored floor, not the generated one. See store().
        $authored = GuideKind::from(self::apiKind($guide));
        $minimum = $authored->minimumItems();

        if ($publish && $guide->picks()->count() < $minimum) {
            throw ValidationException::withMessages([
                'guide' => "A {$authored->value} guide needs at least {$minimum} items before it is worth a reader's time.",
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
            'data' => $this->payload($guide->refresh()->load('picks.group')),
        ]);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        return $request->validate([
            'market' => ['required', Rule::in(Market::values())],
            'title' => ['required', 'string', 'max:160'],

            /*
             * `buying` needs a shortlist; `advice` needs none.
             *
             * Defaulted rather than required, because every guide that existed
             * before this column was a buying guide and a caller written
             * against the old shape should keep working.
             */
            'kind' => ['nullable', Rule::in(GuideKind::values())],

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

            // The per-kind floor is enforced in store(), where the kind is
            // known; a rule here would have to be `required` for one kind and
            // absent for the other, which `Rule::requiredIf` can express and
            // cannot explain.
            'items' => ['nullable', 'array', 'max:'.self::MAX_ITEMS],
            'items.*.groupId' => ['required', 'integer'],
            // A short "best for X" label. The most-read words on the page.
            'items.*.verdict' => ['nullable', 'string', 'max:120'],
            'items.*.copy' => ['nullable', 'string', 'max:1200'],
        ]);
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

    /**
     * The kind this API has always spoken, from the kind the table now holds.
     *
     * `GuideKind` was `buying|advice` and `CoveKind` is five values, of which
     * three live in the `/guides` space. The API keeps the old vocabulary
     * because it is a published contract with an outside writer, and widening it
     * would break every caller for no benefit they asked for.
     *
     * A seasonal Cove reports as `buying`, which is what it is from the API's
     * point of view: a shortlist with prose around it. Its season is a
     * scheduling fact set in the planner, not something a writer supplies.
     */
    private static function apiKind(DailyPickSet $guide): string
    {
        return $guide->kind === CoveKind::Advice
            ? GuideKind::Advice->value
            : GuideKind::Buying->value;
    }

    /** @return array<string, mixed> */
    private function payload(DailyPickSet $guide): array
    {
        return [
            'id' => $guide->id,
            'market' => $guide->market->value,
            'slug' => $guide->slug,
            'title' => $guide->theme_title,
            'kind' => self::apiKind($guide),
            'intro' => $guide->theme_blurb,
            'bodyMd' => $guide->body,
            'metaDescription' => $guide->meta_description,
            'focusKeyphrase' => $guide->focus_keyphrase,
            'faq' => $guide->faq,
            'sourceQueries' => $guide->source_queries,
            'sourceVolume' => $guide->source_volume,
            'status' => $guide->status->value,
            'publishedAt' => $guide->published_at?->toIso8601String(),
            'url' => '/'.$guide->market->value.'/guides/'.$guide->slug,
            'items' => $guide->picks
                ->map(fn (DailyPick $pick) => [
                    'rank' => $pick->rank,
                    'verdict' => $pick->verdict,
                    'copy' => $pick->blurb,
                    'product' => $pick->group instanceof ProductGroup
                        ? $this->lookup->describe($pick->group)
                        : null,
                ])
                ->all(),
        ];
    }
}
