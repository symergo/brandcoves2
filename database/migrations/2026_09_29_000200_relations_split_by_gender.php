<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The relations split by gender (owner, 2026-09-29): the six combined ones go,
 * "to keep simpler code". See docs/features/relations-by-gender.md.
 *
 * Nothing that was known is thrown away. A product tagged for a combined
 * relation suited both, so it now carries both gendered tags: 25,725 products
 * on production carried one, most of the catalogue tagging done so far.
 *
 * - `product_groups.gift_tags` / `crowd_tags`, `offline_ideas.tags`:
 *   `recipient:grandparent` becomes `recipient:grandmother` and
 *   `recipient:grandfather`, and so on.
 * - `gift_landings` rows for a combined relation go (878 on production): the
 *   model casts `recipient` to the enum, which no longer has those values, and
 *   the nightly planner (or `bc:plan-gift-landings` after the deploy) builds
 *   the gendered pages.
 * - `cove_plans.brief.relationship` holding a combined value loses the key
 *   (12 on production, the relation Coves "voor oma en opa", ...): the Coves
 *   stay, about both, and a brief with no relationship reads as "anybody".
 * - `crowd_picks` rows counted for a combined relation go; the nightly count
 *   makes them again under the new values.
 *
 * The mapping is written out here rather than read from the enum, so this
 * migration means the same thing whatever the enum says later.
 *
 * `jsonb_exists()` rather than the `?` operator: through PDO a bare `?` is a
 * placeholder. Forward-only, like every migration here: down() does nothing.
 */
return new class extends Migration
{
    private const SPLIT = [
        'grandparent' => ['grandmother', 'grandfather'],
        'child' => ['son', 'daughter'],
        'sibling' => ['brother', 'sister'],
        'friend' => ['male_friend', 'female_friend'],
        'teacher' => ['female_teacher', 'male_teacher'],
        'host' => ['male_host', 'female_host'],
    ];

    public function up(): void
    {
        foreach (self::SPLIT as $old => [$a, $b]) {
            foreach ([['product_groups', 'gift_tags'], ['product_groups', 'crowd_tags'], ['offline_ideas', 'tags']] as [$table, $column]) {
                DB::statement(
                    "UPDATE {$table} SET {$column} = (
                        SELECT COALESCE(jsonb_agg(DISTINCT tag), '[]'::jsonb) FROM (
                            SELECT jsonb_array_elements_text({$column} - ?) AS tag
                            UNION ALL SELECT ?
                            UNION ALL SELECT ?
                        ) AS split
                    )
                    WHERE jsonb_exists({$column}, ?)",
                    ["recipient:{$old}", "recipient:{$a}", "recipient:{$b}", "recipient:{$old}"],
                );
            }
        }

        $old = array_keys(self::SPLIT);

        DB::table('gift_landings')->whereIn('recipient', $old)->delete();

        DB::table('cove_plans')
            ->whereIn(DB::raw("brief->>'relationship'"), $old)
            ->update(['brief' => DB::raw("brief - 'relationship'")]);

        DB::table('crowd_picks')
            ->where(function ($q) use ($old): void {
                foreach ($old as $value) {
                    $q->orWhere('context', "recipient:{$value}")
                        ->orWhere('context', 'like', "recipient:{$value}+%")
                        ->orWhere('context', 'like', "%+recipient:{$value}");
                }
            })
            ->delete();
    }

    public function down(): void
    {
        // Forward-only (CLAUDE.md): the gendered tags stay.
    }
};
