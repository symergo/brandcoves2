<?php

declare(strict_types=1);

namespace App\Services\Cove;

use App\Models\CovePlan;

/**
 * What a plan looked like when it was handed out.
 *
 * A scheduled agent retries, and two runs must not overwrite each other or a
 * person's edit: `POST /coves/{id}/editorial` requires the revision it was given
 * and answers 409 with the current state when it is stale.
 *
 * ## Why it is a class rather than a method on one controller
 *
 * It began as a private method on `CoveQueueController`, which is where the
 * revision was issued — and that endpoint only lists plans with **no prose yet**.
 * So a plan could be written once and then never revised through the narrow
 * endpoint again, because the only place that minted a revision refused to show
 * it. The way back was the whole-plan upsert, which replaces the shortlist
 * wholesale, so "fix a sentence" meant re-sending the products and risking the
 * curation.
 *
 * `GET /coves/{id}` now returns one too. Two callers computing the same hash
 * separately is how they end up computing it differently — and a revision that
 * disagrees between the endpoint that issues it and the endpoint that checks it
 * rejects every write with a message about somebody else's edit.
 *
 * ## What it covers
 *
 * The plan's own timestamp and its item ids: both "somebody rewrote the brief"
 * and "somebody removed a product" make prose written against it stale. Item
 * *contents* are deliberately not in it — an author revising their own copy
 * would otherwise invalidate the revision they were about to quote.
 */
final readonly class PlanRevision
{
    public static function of(CovePlan $plan): string
    {
        return substr(hash('sha256', implode('|', [
            $plan->updated_at?->toIso8601String() ?? '',
            $plan->items()->orderBy('id')->pluck('id')->implode(','),
        ])), 0, 16);
    }

    /** Is the revision a client quoted still the current one? */
    public static function matches(CovePlan $plan, string $quoted): bool
    {
        return hash_equals(self::of($plan), $quoted);
    }
}
