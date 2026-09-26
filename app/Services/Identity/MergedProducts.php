<?php

declare(strict_types=1);

namespace App\Services\Identity;

use Illuminate\Support\Facades\DB;

/**
 * Which product ids were merged, and into what.
 *
 * For prose written before a merge: a Cove saying `[[product:123|the kit]]`
 * keeps saying it after 123 is merged into 456, and the Cove's own item list
 * has moved to 456 with the merge. Asking here turns the old id into the one
 * the page now holds, so the link still renders instead of falling back to
 * plain text.
 *
 * One query for all the ids of a text, and none when there are none, which is
 * nearly always: CoveMarkup only asks about ids its allowlist does not know.
 */
class MergedProducts
{
    /**
     * @param  list<int>  $ids
     * @return array<int, int> merged id => the product it lives on as
     */
    public function winners(array $ids): array
    {
        $ids = array_values(array_unique(array_filter($ids, fn (int $id) => $id > 0)));

        if ($ids === []) {
            return [];
        }

        // One hop is the stored shape (GroupMerger keeps it that way), so a
        // single lookup is the whole answer.
        return DB::table('product_groups')
            ->whereIn('id', $ids)
            ->whereNotNull('merged_into_id')
            ->pluck('merged_into_id', 'id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }
}
