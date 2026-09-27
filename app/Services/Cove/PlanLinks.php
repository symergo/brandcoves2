<?php

declare(strict_types=1);

namespace App\Services\Cove;

use App\Models\CovePlan;

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
 * `EntityRails` supplies the second half (through `EntityLinks`), so what a
 * writer may link to and what a reader sees under the writing come from one
 * query.
 */
final readonly class PlanLinks
{
    public function __construct(private EntityLinks $links) {}

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
     * Live rather than stored: a writer is writing now, so the list they are
     * handed is today's. The page reads the list `EditionBuilder` stored with
     * the built Cove instead, see EntityLinks.
     *
     * @return list<string>
     */
    private function entityVocabulary(CovePlan $plan): array
    {
        return $this->links->compute($plan->kind, $plan->market, (string) $plan->slug);
    }
}
