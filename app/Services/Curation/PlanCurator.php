<?php

declare(strict_types=1);

namespace App\Services\Curation;

use App\Enums\Source;
use App\Models\CovePlan;
use App\Models\CovePlanItem;
use App\Models\ProductGroup;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Everything that changes a Cove's shortlist.
 *
 * Kept out of the Filament page and out of the API controller because both of
 * them do it and neither of them should own it: the panel and the editorial API
 * have to produce identical shortlists, and "the admin panel is decorative" is
 * a failure that only shows up when somebody tries to use what it produced.
 */
class PlanCurator
{
    /**
     * Add one search result to a plan, at the end of the list.
     *
     * The key is the opaque one a CurationResult carries — `group:123` for a
     * catalogue product, `amazon:B01ABC` for a source that may not be mirrored.
     * One string rather than a pair of arguments because it is what a button in
     * a list can carry, and validating it here is what keeps the page from
     * having to know the difference.
     */
    public function add(CovePlan $plan, string $key, ?User $curator = null, ?string $note = null): CovePlanItem
    {
        [$kind, $id] = $this->parse($key);

        if ($kind === 'group') {
            $group = ProductGroup::query()
                ->forMarket($plan->market)
                ->find((int) $id);

            if ($group === null) {
                /*
                 * Market-scoped, and this is invariant 2 rather than a lookup
                 * failing. The same product in two markets has different tax,
                 * shipping and availability; a Dutch group on a Belgian Cove
                 * would present a price the reader cannot pay.
                 */
                throw new InvalidArgumentException("Product {$id} is not available in {$plan->market->value}.");
            }

            return $this->append($plan, ['group_id' => $group->id], $curator, $note);
        }

        $source = Source::tryFrom($kind);

        if ($source === null || $source->allowsCatalogueStorage()) {
            /*
             * A mirrorable source has no business being stored as a decision:
             * it is already in the catalogue, with a group id, offers and price
             * history, and storing it by external id would produce a second
             * unlinked copy of a product we can already compare properly.
             */
            throw new InvalidArgumentException("Not a live-only source: {$kind}.");
        }

        return $this->append($plan, [
            'source' => $source->value,
            'external_id' => $id,
        ], $curator, $note);
    }

    /**
     * Put the engine's suggestion on a plan that has none.
     *
     * The starting point a curator reacts to. Deliberately refuses a plan that
     * already has items: a prefill is a *first* draft, and re-running the
     * planner must never append a second set of seven underneath somebody's
     * curation — that is the shape of edit somebody only notices after the page
     * has published.
     *
     * @param  list<ProductGroup>  $groups
     * @return int How many were written.
     */
    public function prefill(CovePlan $plan, array $groups): int
    {
        if ($groups === [] || $plan->items()->exists()) {
            return 0;
        }

        return DB::transaction(function () use ($plan, $groups): int {
            foreach ($groups as $rank => $group) {
                $plan->items()->create([
                    'group_id' => $group->id,
                    'rank' => $rank + 1,
                    // No note. The note is the reason a *person* chose it, and
                    // the machine has none to give — filling it with "suggested
                    // by the planner" would put that sentence in the writer's
                    // brief, which is the one place it must not appear.
                    'added_by' => null,
                ]);
            }

            return count($groups);
        });
    }

    public function remove(CovePlanItem $item): void
    {
        $item->delete();
    }

    /**
     * Rewrite the order from a list of item ids.
     *
     * Ranks are renumbered from 1 on every reorder rather than patched. Gaps
     * and ties are legal in the schema — rank is not unique, deliberately, so
     * that a drag does not have to dance around an index — but an ordering that
     * accumulates them becomes one where "move this up" stops meaning anything.
     *
     * @param  list<int>  $orderedIds
     */
    public function reorder(CovePlan $plan, array $orderedIds): void
    {
        DB::transaction(function () use ($plan, $orderedIds): void {
            $rank = 1;

            foreach ($orderedIds as $id) {
                $updated = CovePlanItem::query()
                    // Scoped to the plan: an id from another plan arriving in
                    // this list would otherwise be renumbered into it.
                    ->where('plan_id', $plan->id)
                    ->where('id', $id)
                    ->update(['rank' => $rank]);

                if ($updated > 0) {
                    $rank++;
                }
            }
        });
    }

    /** The next rank on the list, so an addition lands at the bottom. */
    private function append(CovePlan $plan, array $identity, ?User $curator, ?string $note): CovePlanItem
    {
        return DB::transaction(function () use ($plan, $identity, $curator, $note): CovePlanItem {
            /*
             * The last rank, read under a row lock.
             *
             * `max('rank')` would be the obvious query and Postgres refuses it:
             * "FOR UPDATE is not allowed with aggregate functions". Ordering
             * and taking one row locks the row the next rank is derived from,
             * which is what two curators adding at once actually need.
             */
            $rank = (int) CovePlanItem::query()
                ->where('plan_id', $plan->id)
                ->orderByDesc('rank')
                ->lockForUpdate()
                ->value('rank');

            return CovePlanItem::create([
                ...$identity,
                'plan_id' => $plan->id,
                'rank' => $rank + 1,
                'note' => $note,
                'added_by' => $curator?->id,
            ]);
        });
    }

    /** @return array{0: string, 1: string} */
    private function parse(string $key): array
    {
        $parts = explode(':', $key, 2);

        if (count($parts) !== 2 || trim($parts[1]) === '') {
            throw new InvalidArgumentException("Unreadable product key: {$key}.");
        }

        return [$parts[0], trim($parts[1])];
    }
}
