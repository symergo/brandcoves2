<?php

declare(strict_types=1);

namespace App\Services\Curation;

use App\Enums\Source;
use App\Models\CovePlan;
use App\Models\ProductGroup;
use App\Services\Connectors\Offer;
use App\Services\Search\SearchQuery;
use App\Services\Search\SearchService;
use Illuminate\Support\Facades\DB;

/**
 * The search a curator uses to fill a Cove's shortlist.
 *
 * Deliberately thin. It builds a SearchQuery, hands it to SearchService and
 * flattens the answer — it does not query anything itself, and that is the
 * point: SearchService is already the one thing that asks *every* merchant.
 * It calls the live connectors, folds the mirrorable offers into
 * `products`/`product_groups` in the same request, attributes brands to sources
 * that supply none, and degrades to the catalogue when a connector is down or
 * cooling down after a 429.
 *
 * The practical consequence is the feature the curator actually notices: a bol
 * product that has never been ingested can be searched for, found, and pinned
 * to a plan **in one request**, because by the time the results render it is a
 * real group with a real id.
 *
 * A second retrieval path here would have to reimplement all of that and would
 * drift from the search a visitor gets, which is the search whose results a
 * curated page has to sit alongside.
 */
class CurationSearch
{
    public function __construct(
        private readonly SearchService $search,
        private readonly ScheduleConflicts $conflicts,
    ) {}

    /**
     * @return list<CurationResult>
     */
    /**
     * @param  int|null  $maxPrice  Cents. A budget is the commonest constraint a
     *                              curator works under, and filtering in SQL beats
     *                              scrolling past everything over it.
     */
    public function search(CovePlan $plan, string $term, ?int $maxPrice = null, int $limit = 24): array
    {
        $term = trim($term);

        if ($term === '') {
            return [];
        }

        $result = $this->search->search(new SearchQuery(
            market: $plan->market,
            term: $term,
            maxPrice: $maxPrice,
            // In stock only: a curator choosing something unbuyable has chosen
            // a card the edition will filter out at render.
            inStockOnly: true,
            // Curation is not a deals page. The constructor defaults this to
            // true, which would quietly hide every full-price product.
            discountedOnly: false,
            sort: 'relevance',
            page: 1,
            /*
             * Load-bearing, not hygiene.
             *
             * `search_log` is the site's demand signal: it feeds the buying-guide
             * topic queue and the related-search chips on public pages. An
             * editor curating "kerstcadeau man 40" for an afternoon would
             * otherwise manufacture demand nobody expressed, and a guide would
             * be written about it — with no way afterwards to tell the invented
             * rows from the real ones.
             */
            logged: false,
        ));

        $taken = $this->taken($plan);

        $groups = collect($result->groups->items())->take($limit);
        $ids = $groups->pluck('id')->all();
        $sources = $this->sourcesFor($ids);

        /*
         * Whether each result is already spoken for elsewhere.
         *
         * The mistake a curator makes is not picking a bad product, it is
         * picking a good one twice — and the repeat memory that catches this
         * for the engine deliberately does not apply to a person's choices.
         */
        $conflicts = $this->conflicts->for($plan->market, $ids, $plan->id);

        $rows = $groups
            ->map(fn (ProductGroup $group) => CurationResult::group(
                groupId: $group->id,
                title: $group->title,
                brand: $group->brand,
                imageUrl: $group->image_url,
                price: $group->min_price,
                merchantCount: (int) $group->merchant_count,
                inStock: (bool) $group->in_stock,
                sources: $sources[$group->id] ?? [],
                alreadyAdded: isset($taken['group:'.$group->id]),
                conflict: $conflicts[$group->id] ?? null,
            ))
            ->all();

        /*
         * The unmirrorable half, appended rather than interleaved.
         *
         * These carry no group id, no shop count and no price history, so they
         * cannot be ranked against the stored rows on anything the stored rows
         * are ranked on. Sorting them into the same list by a made-up score
         * would present a guess as a ranking.
         */
        foreach ($result->liveOffers as $offer) {
            /** @var Offer $offer */
            $rows[] = CurationResult::live(
                source: $offer->source,
                externalId: $offer->externalId,
                title: $offer->title,
                brand: $offer->brand,
                imageUrl: $offer->imageUrl,
                price: $offer->price,
                alreadyAdded: isset($taken[$offer->source->value.':'.$offer->externalId]),
            );
        }

        return array_slice($rows, 0, $limit + count($result->liveOffers));
    }

    /**
     * What is already on this plan, keyed the way a result is.
     *
     * So the screen can say "added" instead of offering a duplicate that the
     * unique index would reject with a database error.
     *
     * @return array<string, true>
     */
    private function taken(CovePlan $plan): array
    {
        $keys = [];

        foreach ($plan->items()->get(['group_id', 'source', 'external_id']) as $item) {
            $keys[$item->group_id !== null
                ? 'group:'.$item->group_id
                : $item->source->value.':'.$item->external_id] = true;
        }

        return $keys;
    }

    /**
     * Which sources each group's offers came from.
     *
     * One aggregate for the page rather than a relation load per card. The
     * badge it feeds is the only way a curator can tell that the product they
     * are looking at arrived from a live connector during *this* search rather
     * than from the nightly ingest — which is worth knowing, because it is the
     * difference between a product the catalogue has history for and one it met
     * a second ago.
     *
     * @param  list<int>  $ids
     * @return array<int, list<Source>>
     */
    private function sourcesFor(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return DB::table('products')
            ->select('group_id', 'source')
            ->whereIn('group_id', $ids)
            ->where('status', 'active')
            ->distinct()
            ->get()
            ->groupBy('group_id')
            ->map(fn ($rows) => $rows
                ->map(fn ($row) => Source::tryFrom($row->source))
                ->filter()
                ->values()
                ->all())
            ->all();
    }
}
