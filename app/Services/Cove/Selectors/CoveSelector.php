<?php

declare(strict_types=1);

namespace App\Services\Cove\Selectors;

use App\Models\CovePlan;
use App\Models\ProductGroup;
use Illuminate\Support\Collection;

/**
 * How a Cove of a given kind composes its page.
 *
 * Curation is the same for every kind and is resolved by the builder: the
 * shortlist a person chose leads the page, in their order, and a locked plan is
 * the page outright. What differs is what goes *underneath* — and the two
 * answers are genuinely different editorial arguments rather than two tunings of
 * one.
 *
 * A Daily is a column. Its remaining slots want variety and surprise: things
 * that rank badly everywhere else, spread across categories, never repeating
 * last month — but drawn from the day's own theme, and left empty rather than
 * filled from the market at large when the theme runs out.
 *
 * A guide is a comparison. Its remaining slots want one product per brand,
 * ordered by price, all of them carrying several shops — because the reader is
 * choosing between them, and seven versions of the same thing at seven prices is
 * not a choice.
 *
 * Asking one function to do both with a flag is how you get a function nobody
 * can change safely.
 *
 * The curated lead is passed in rather than concatenated afterwards because both
 * strategies have to *account* for it: the column spends its category variety
 * around what a person already chose, and the guide's one-per-brand rule has to
 * know which brands a person already spent. Neither may drop a curated product
 * to satisfy its own rule.
 */
interface CoveSelector
{
    /**
     * The products this page will show, in the order they will appear.
     *
     * @param  Collection<int, ProductGroup>  $curated  the curator's shortlist,
     *                                                  in their order, already
     *                                                  filtered to presentable
     * @param  int  $count  how many the page wants in total
     * @param  list<int>  $exclude  group ids that must not be added — what the
     *                              planner has already spoken for, and on a redo,
     *                              whatever is on the page right now
     * @return list<ProductGroup>
     */
    public function select(CovePlan $plan, Collection $curated, int $count, array $exclude = []): array;
}
