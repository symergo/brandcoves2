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
use App\Services\Identity\Gtin;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
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

            /*
             * A barcode, which is not a search term.
             *
             * `q` runs against `products.search_vector`, which is weighted title
             * / brand / category / description and contains **no EAN at all**.
             * So an author holding a list of barcodes — the most natural way to
             * hand over a shortlist, and how a merchant's own export is keyed —
             * got an empty array back, which reads as "we don't stock it" rather
             * than "you asked the wrong way".
             *
             * The catalogue answers this perfectly well and the shopper path
             * already does it: normalise, then hit the `(market, identity_key)`
             * unique index. Until now the only way through the editorial API was
             * the *public* scan endpoint plus parsing a group id out of the URL
             * it returned, which is what the seed skill spends most of its words
             * on.
             */
            'ean' => ['nullable', 'string', 'max:20'],
        ]);

        $market = Market::from($data['market']);

        if (filled($data['ean'] ?? null)) {
            return $this->byBarcode($market, (string) $data['ean'], $request->boolean('includeLive'));
        }

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

    /**
     * One product by its barcode, in this market.
     *
     * Deliberately shaped like the rest of `/products` — `data` is a list, empty
     * on a miss — so a client can treat it as the same call with a different
     * question rather than a special case with its own parsing.
     *
     * Three outcomes an author has to be able to tell apart, which is why the
     * invalid case is a 422 rather than an empty list:
     *
     *   - **invalid**: the barcode failed its check digit. A misread, not a miss,
     *     and retrying it in another market will fail too.
     *   - **not found**: no EAN-grouped product here. Note that a feed shipping
     *     no barcode leaves its products grouped by brand and title instead, so
     *     the site can hold a product this cannot see.
     *   - **found**: exactly one group, because `(market, identity_key)` is
     *     unique.
     *
     * `includeLive` asks the live sources first, for the case above: bol is
     * queried rather than crawled, so a product nobody has ingested can be
     * fetched, grouped and then found here in the same request.
     */
    private function byBarcode(Market $market, string $barcode, bool $includeLive): JsonResponse
    {
        $gtin = Gtin::normalise($barcode);

        if ($gtin === null) {
            throw ValidationException::withMessages([
                'ean' => "'{$barcode}' is not a valid barcode — it fails its check digit. "
                    .'That is a misread rather than a product we do not carry, so trying another market will not help.',
            ]);
        }

        if ($includeLive) {
            /*
             * The body of a live lookup is useless and its side effect is not.
             *
             * `SearchService` treats a GTIN as an identity and will ingest and
             * group what bol returns — but its own reply is filtered by the same
             * full-text query that cannot match a barcode, so it comes back
             * empty either way. Run it for the ingest, then ask the catalogue.
             */
            $this->lookup->search(market: $market, term: $gtin, limit: 1, includeLive: true);
        }

        $group = ProductGroup::query()
            ->forMarket($market)
            ->where('identity_key', $gtin)
            ->first();

        return response()->json([
            'market' => $market->value,
            'ean' => $gtin,
            'count' => $group === null ? 0 : 1,
            'data' => $group === null ? [] : [$this->lookup->describe($group)],
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
