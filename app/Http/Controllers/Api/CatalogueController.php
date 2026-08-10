<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Enums\Market;
use App\Enums\ProductStatus;
use App\Http\Controllers\Controller;
use App\Models\GuideTopic;
use App\Models\Product;
use App\Models\ProductGroup;
use App\Services\Editorial\ProductLookup;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The grounding endpoints: what exists, and what people are asking for.
 *
 * These are the reason the rest of the API can be trusted. An author who can
 * only reference ids returned from here cannot write about a product that does
 * not exist, is out of stock, or belongs to a different market — the three
 * mistakes a writer with no catalogue access makes immediately and confidently.
 *
 * Read-only, and cheap: no AI, no live connector calls, no writes beyond the
 * token's own last-used stamp.
 */
class CatalogueController extends Controller
{
    public function __construct(private readonly ProductLookup $lookup) {}

    /** Find products to write about. */
    public function products(Request $request): JsonResponse
    {
        $data = $request->validate([
            'market' => ['required', Rule::in(Market::values())],
            'q' => ['nullable', 'string', 'max:120'],
            'category' => ['nullable', 'string', 'max:120'],
            'brand' => ['nullable', 'string', 'max:120'],
            /*
             * Also ask the live sources — bol today.
             *
             * Off by default because it costs an upstream call and most lookups
             * are answered by the catalogue. On, it is the difference between
             * "the four best kitchen scales" and "the four best kitchen scales
             * that happen to be in an Awin feed", which is a limit the article
             * would never admit to.
             *
             * What comes back is not a second class of result: the live offers
             * are ingested and grouped before the query runs, so they arrive as
             * ordinary product groups with ordinary ids. See ProductLookup.
             */
            'includeLive' => ['nullable', 'boolean'],
            // Cents, like everything else. A "max price" of 50 means fifty
            // cents, and saying so in the field name is cheaper than a support
            // question about why the results are empty.
            'minPriceCents' => ['nullable', 'integer', 'min:0'],
            'maxPriceCents' => ['nullable', 'integer', 'min:0'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $market = Market::from($data['market']);

        $groups = $this->lookup->search(
            market: $market,
            term: $data['q'] ?? null,
            category: $data['category'] ?? null,
            brand: $data['brand'] ?? null,
            minPrice: isset($data['minPriceCents']) ? (int) $data['minPriceCents'] : null,
            maxPrice: isset($data['maxPriceCents']) ? (int) $data['maxPriceCents'] : null,
            limit: (int) ($data['limit'] ?? 24),
            includeLive: $request->boolean('includeLive'),
        );

        return response()->json([
            'market' => $market->value,
            'count' => $groups->count(),
            'data' => $groups->map(fn (ProductGroup $g) => $this->lookup->describe($g))->all(),
        ]);
    }

    public function product(ProductGroup $group): JsonResponse
    {
        return response()->json([
            'data' => [
                ...$this->lookup->describe($group),
                /*
                 * The offers, because comparison is the substance of the site
                 * and "sold by four shops" is a fact worth a sentence. Merchant
                 * and price only — an author has no use for affiliate URLs, and
                 * those are hostile third-party input that should travel as far
                 * as the redirect handler and no further.
                 */
                'offers' => $group->offers()
                    ->where('status', ProductStatus::Active->value)
                    ->with('merchant:id,name')
                    ->orderBy('price')
                    ->limit(10)
                    ->get(['id', 'merchant_id', 'price', 'source'])
                    ->map(fn (Product $offer) => [
                        'merchant' => $offer->merchant?->name,
                        'priceCents' => $offer->price,
                        'source' => $offer->source->value,
                    ])
                    ->all(),
            ],
        ]);
    }

    /**
     * Guide topics, ranked by evidenced demand.
     *
     * This is the honest answer to "what should I write about": every row is a
     * cluster of queries visitors actually typed into this site in the last
     * thirty days. A guide written against one of these has an audience before
     * it is published, which is the whole reason guides rank at all.
     */
    public function topics(Request $request): JsonResponse
    {
        $data = $request->validate([
            'market' => ['required', Rule::in(Market::values())],
            'status' => ['nullable', 'string', 'max:30'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $topics = GuideTopic::query()
            ->where('market', $data['market'])
            ->when(
                isset($data['status']),
                fn ($q) => $q->where('status', $data['status']),
                // Candidates by default: published ones already have a guide,
                // and offering them as topics invites a duplicate.
                fn ($q) => $q->where('status', 'candidate'),
            )
            ->orderByDesc('score')
            ->limit((int) ($data['limit'] ?? 25))
            ->get();

        return response()->json([
            'market' => $data['market'],
            'count' => $topics->count(),
            'data' => $topics->map(fn (GuideTopic $t) => [
                'id' => $t->id,
                'topic' => $t->topic,
                'memberQueries' => $t->member_queries,
                'searchVolume' => $t->search_volume,
                'availableProducts' => $t->available_products,
                'score' => $t->score,
                'status' => $t->status,
                // A topic the builder has already failed on is a topic whose
                // catalogue is thin, not a topic nobody got round to. Saying so
                // stops an author picking it and finding five products.
                'lastAttemptAt' => $t->last_attempt_at?->toIso8601String(),
                'attempts' => $t->attempts,
            ])->all(),
        ]);
    }

    /** Resolve a `{market}` route segment or 404. */
    public static function market(string $market): Market
    {
        return Market::tryFrom($market) ?? throw new NotFoundHttpException("Unknown market '{$market}'.");
    }
}
