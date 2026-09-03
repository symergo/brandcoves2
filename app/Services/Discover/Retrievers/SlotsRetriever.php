<?php

declare(strict_types=1);

namespace App\Services\Discover\Retrievers;

use App\Jobs\WidenGiftAngles;
use App\Models\ProductGroup;
use App\Services\Discover\Candidate;
use App\Services\Discover\DiscoveryRequest;
use Illuminate\Support\Facades\DB;

/**
 * Decompose a goal into component slots and fill each one.
 *
 * The Projects mode. "I'm setting up a home office" is not a search — it is
 * five searches whose answers have to work together and add up to a number.
 *
 * ## The slot map is curated, not generated
 *
 * A model could decompose an arbitrary goal, and doing it per request would be
 * an AI call in the request path — which is the one thing the invariant forbids.
 * It would also be non-deterministic: the same goal producing a different kit on
 * a reload makes the running total meaningless.
 *
 * So the map is a table, in the repo. It covers the goals people actually
 * arrive with; anything unmatched falls back to treating the goal as one slot,
 * which degrades to a normal search rather than to nothing. Widening the map
 * offline is the same shape as {@see WidenGiftAngles} and is where
 * a model belongs if one is ever wanted.
 *
 * ## One product per slot, budget split by weight
 *
 * A kit is only useful if the parts do not all cost the same. The weights are
 * where the money should go: in a home office the chair matters more than the
 * lamp, so it gets more of the budget rather than an equal share.
 */
class SlotsRetriever implements Retriever
{
    /**
     * Goal keyword => slots, each with a query and a share of the budget.
     *
     * Weights sum to 1 per goal. Queries are concrete product nouns for the
     * same reason as the gift angle seed: "bureau" retrieves desks, "thuiswerk
     * inspiratie" retrieves blog posts.
     *
     * @var array<string, array<string, array{query: string, weight: float}>>
     */
    private const GOALS = [
        'thuiswerk' => [
            'bureau' => ['query' => 'bureau zit sta', 'weight' => 0.35],
            'stoel' => ['query' => 'bureaustoel ergonomisch', 'weight' => 0.3],
            'scherm' => ['query' => 'monitor 27 inch', 'weight' => 0.2],
            'verlichting' => ['query' => 'bureaulamp', 'weight' => 0.08],
            'randapparatuur' => ['query' => 'toetsenbord muis draadloos', 'weight' => 0.07],
        ],
        'koffie' => [
            'machine' => ['query' => 'espressomachine', 'weight' => 0.55],
            'molen' => ['query' => 'koffiemolen', 'weight' => 0.25],
            'weegschaal' => ['query' => 'precisie weegschaal', 'weight' => 0.1],
            'kannen' => ['query' => 'melkkan latte', 'weight' => 0.1],
        ],
        'gaming' => [
            'scherm' => ['query' => 'gaming monitor 144hz', 'weight' => 0.4],
            'headset' => ['query' => 'gaming headset', 'weight' => 0.2],
            'toetsenbord' => ['query' => 'mechanisch toetsenbord', 'weight' => 0.2],
            'muis' => ['query' => 'gaming muis', 'weight' => 0.2],
        ],
        'keuken' => [
            'pannen' => ['query' => 'pannenset', 'weight' => 0.35],
            'messen' => ['query' => 'koksmes', 'weight' => 0.25],
            'machine' => ['query' => 'keukenmachine', 'weight' => 0.3],
            'planken' => ['query' => 'snijplank', 'weight' => 0.1],
        ],
        'baby' => [
            'wieg' => ['query' => 'babybed', 'weight' => 0.35],
            'kinderwagen' => ['query' => 'kinderwagen', 'weight' => 0.35],
            'monitor' => ['query' => 'babyfoon', 'weight' => 0.2],
            'stoel' => ['query' => 'kinderstoel', 'weight' => 0.1],
        ],
        'verhuis' => [
            'stofzuiger' => ['query' => 'stofzuiger', 'weight' => 0.3],
            'wasmachine' => ['query' => 'wasmachine', 'weight' => 0.4],
            'strijk' => ['query' => 'strijkijzer', 'weight' => 0.1],
            'gereedschap' => ['query' => 'gereedschapskoffer', 'weight' => 0.2],
        ],
    ];

    public function key(): string
    {
        return 'slots';
    }

    public function isAvailable(DiscoveryRequest $request): bool
    {
        return $request->goal !== null && trim($request->goal) !== '';
    }

    public function retrieve(DiscoveryRequest $request, int $take): array
    {
        $slots = $this->slotsFor((string) $request->goal);
        $budget = $request->budgetMax;
        $candidates = [];

        foreach ($slots as $name => $slot) {
            /*
             * Each slot gets its share of the budget as a ceiling, and a floor
             * at 40% of that share. Without the floor the cheapest thing in
             * every slot wins and the kit is five compromises; the ceiling on
             * its own would let one slot eat the budget.
             */
            $ceiling = $budget === null ? null : (int) round($budget * $slot['weight']);
            $floor = $ceiling === null ? null : (int) round($ceiling * 0.4);

            $group = ProductGroup::query()
                ->forMarket($request->market)
                ->presentable()
                ->when(
                    $request->excludeGroupIds !== [],
                    fn ($q) => $q->whereNotIn('id', $request->excludeGroupIds),
                )
                ->when($ceiling !== null, fn ($q) => $q->where('min_price', '<=', $ceiling))
                ->when($floor !== null, fn ($q) => $q->where('min_price', '>=', $floor))
                ->whereExists(fn ($sub) => $sub
                    ->select(DB::raw(1))
                    ->from('products')
                    ->whereColumn('products.group_id', 'product_groups.id')
                    ->where('products.status', 'active')
                    ->whereRaw(
                        // Bound, not read off the row. See
                        // TopicMiner::availableProducts for why this one detail
                        // decides whether the full-text index is reachable.
                        'products.search_vector @@ websearch_to_tsquery(bc_text_config(?), ?)',
                        [$request->market->value, $slot['query']]
                    ))
                // Comparable first: a kit you can price-check part by part is a
                // kit someone will actually buy.
                ->orderByDesc('merchant_count')
                ->orderByRaw('word_similarity(?, product_groups.title) DESC', [$slot['query']])
                ->first();

            if ($group === null) {
                // A missing slot is reported by its absence rather than filled
                // with something wrong. A kit with four of five parts is
                // honest; a kit with a mismatched fifth is not.
                continue;
            }

            $candidates[] = (new Candidate($group))->withSignals([
                'relevance' => 1.0,
                'quality' => $group->merchant_count > 1 ? 1.0 : 0.8,
                'novelty' => 0.3,
                'unexpectedness' => min(1.0, ((float) ($group->surprise_score ?? 30)) / 100),
                // Carried so the layout can group by slot and show a running
                // total. Not a scoring signal — the ranker ignores it.
                'slot:'.$name => 1.0,
            ], $this->key());
        }

        return array_slice($candidates, 0, max($take, count($slots)));
    }

    /**
     * @return array<string, array{query: string, weight: float}>
     */
    private function slotsFor(string $goal): array
    {
        $goal = mb_strtolower($goal);

        foreach (self::GOALS as $keyword => $slots) {
            if (str_contains($goal, $keyword)) {
                return $slots;
            }
        }

        // Unmatched goal: one slot, the goal itself. Degrades to a search
        // rather than to an empty page — the person told us something real.
        return ['algemeen' => ['query' => $goal, 'weight' => 1.0]];
    }
}
