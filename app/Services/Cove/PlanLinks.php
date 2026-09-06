<?php

declare(strict_types=1);

namespace App\Services\Cove;

use App\Enums\CoveKind;
use App\Models\BrandStat;
use App\Models\CovePlan;
use App\Services\Shops\ShopDirectory;

/**
 * What a plan may link to, answered once.
 *
 * ## Why this exists
 *
 * "Which searches may this Cove link to" was computed in six places by three
 * different rules: `CovePrompt` derived them from the curated products'
 * categories, four endpoints passed `$plan->queries`, and the two controllers
 * that *render* an entity page passed the entity's own categories. Three
 * answers to one question, and the disagreement was invisible in the worst
 * direction - the brief told a writer nothing could be linked, the link check
 * called seven valid tokens unresolved, and the page resolved them anyway.
 *
 * Found on 2026-09-06 while writing the first Shop Cove by hand: every symptom
 * pointed at the writing rather than at the tooling, which is what made it cost
 * three separate diagnoses.
 *
 * ## The rule
 *
 * A plan's extra searches are its own `queries`, plus - for a Brand or Shop
 * Cove - the categories that entity actually sells in. An entity Cove curates
 * nothing on purpose, so without the second half it has no vocabulary at all,
 * on the one kind of page whose whole purpose is to link into the search.
 *
 * `EntityRails` supplies the second half, so what a writer may link to and what
 * a reader sees under the writing come from one query.
 */
final readonly class PlanLinks
{
    public function __construct(
        private EntityRails $rails,
        private ShopDirectory $shops,
    ) {}

    /**
     * The searches this plan may link to, beyond its products' own categories.
     *
     * @return list<string>
     */
    public function extraSearches(CovePlan $plan): array
    {
        $queries = array_values(array_filter(array_map(
            'strval',
            (array) $plan->queries,
        )));

        if (! $plan->kind->isEntity()) {
            return $queries;
        }

        return array_values(array_unique([...$queries, ...$this->entityVocabulary($plan)]));
    }

    /**
     * The categories this brand or shop sells in, most first.
     *
     * Empty for a slug naming no shop this market compares or no brand it
     * carries. That is the honest answer rather than a failure: there is nothing
     * to link to, and a token naming a category the entity does not stock
     * renders as plain words wherever it appears.
     *
     * @return list<string>
     */
    private function entityVocabulary(CovePlan $plan): array
    {
        $slug = (string) $plan->slug;

        if ($plan->kind === CoveKind::Shop) {
            $shop = $this->shops->shopFor($plan->market, $slug);

            return $shop === null ? [] : $this->rails->vocabularyForShop($shop, $plan->market);
        }

        $brand = BrandStat::query()
            ->forMarket($plan->market)
            ->where('slug', $slug)
            ->first();

        return $brand === null ? [] : $this->rails->vocabularyForBrand($brand, $plan->market);
    }
}
