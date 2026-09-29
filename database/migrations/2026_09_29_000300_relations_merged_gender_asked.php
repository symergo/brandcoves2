<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The gender split of the relations, undone the same day, and gender asked on
 * its own instead (owner, 2026-09-29: "revert it and ask for gender instead.
 * Products then only get a gender if relevant and there is no duplication").
 * See docs/features/gift-gender.md.
 *
 * `2026_09_29_000200_relations_split_by_gender` gave every product tagged for
 * a combined relation both gendered tags. This puts them back:
 *
 * - A product with both of a pair (all of them, since nothing was tagged in
 *   between: the catalogue tagging is paused) gets the combined tag back.
 * - A product with only one of the pair ("beste oma" mug) gets the combined
 *   tag and a gender tag: that is exactly the case a gender tag is for.
 * - Gendered landing pages go; the planner builds the combined ones again.
 * - The relation Coves get the relationship back that the split took from
 *   their brief, by slug.
 * - Crowd counts under a gendered relation go; the nightly count remakes them.
 * - The "done" idea announcing the split leaves the Denk mee board.
 * - `recipients.gender` and `user_tastes.gender`: man or woman, a profile
 *   question beside the age, on a saved person and on My taste. Nullable, and
 *   a CHECK rather than a native enum (CLAUDE.md).
 *
 * The mapping is written out here, so the migration means the same thing
 * whatever the enum says later. Forward-only: down() does nothing.
 */
return new class extends Migration
{
    /** combined => [one, gender of one, other, gender of other] */
    private const PAIRS = [
        'grandparent' => ['grandmother', 'female', 'grandfather', 'male'],
        'child' => ['son', 'male', 'daughter', 'female'],
        'sibling' => ['brother', 'male', 'sister', 'female'],
        'friend' => ['male_friend', 'male', 'female_friend', 'female'],
        'teacher' => ['female_teacher', 'female', 'male_teacher', 'male'],
        'host' => ['male_host', 'male', 'female_host', 'female'],
    ];

    /** The relation Coves by slug (docs/features/gift-personas.md). */
    private const COVES = [
        'voor-oma-en-opa' => 'grandparent',
        'voor-een-kind' => 'child',
        'voor-een-vriend' => 'friend',
        'voor-je-broer-of-zus' => 'sibling',
        'voor-de-leerkracht' => 'teacher',
        'voor-de-gastheer' => 'host',
    ];

    public function up(): void
    {
        foreach (self::PAIRS as $combined => [$a, $genderA, $b, $genderB]) {
            foreach ([['product_groups', 'gift_tags'], ['product_groups', 'crowd_tags'], ['offline_ideas', 'tags']] as [$table, $column]) {
                // Only one of the pair: the combined tag and that one's gender.
                foreach ([[$a, $b, $genderA], [$b, $a, $genderB]] as [$only, $not, $gender]) {
                    DB::statement(
                        "UPDATE {$table} SET {$column} = (
                            SELECT COALESCE(jsonb_agg(DISTINCT tag), '[]'::jsonb) FROM (
                                SELECT jsonb_array_elements_text({$column} - ?) AS tag
                                UNION ALL SELECT ?
                                UNION ALL SELECT ?
                            ) AS merged
                        )
                        WHERE jsonb_exists({$column}, ?) AND NOT jsonb_exists({$column}, ?)",
                        ["recipient:{$only}", "recipient:{$combined}", "gender:{$gender}", "recipient:{$only}", "recipient:{$not}"],
                    );
                }

                // Both of the pair: the combined tag alone.
                DB::statement(
                    "UPDATE {$table} SET {$column} = (
                        SELECT COALESCE(jsonb_agg(DISTINCT tag), '[]'::jsonb) FROM (
                            SELECT jsonb_array_elements_text({$column} - ? - ?) AS tag
                            UNION ALL SELECT ?
                        ) AS merged
                    )
                    WHERE jsonb_exists({$column}, ?) AND jsonb_exists({$column}, ?)",
                    ["recipient:{$a}", "recipient:{$b}", "recipient:{$combined}", "recipient:{$a}", "recipient:{$b}"],
                );
            }
        }

        $gendered = array_merge(...array_map(fn (array $p) => [$p[0], $p[2]], array_values(self::PAIRS)));

        DB::table('gift_landings')->whereIn('recipient', $gendered)->delete();

        foreach (self::COVES as $slug => $relationship) {
            DB::table('cove_plans')
                ->where('slug', $slug)
                ->whereNotNull('brief')
                // jsonb_exists(), not the `?` operator: through PDO a bare `?` is a placeholder.
                ->whereRaw("NOT jsonb_exists(brief, 'relationship')")
                ->update(['brief' => DB::raw("jsonb_set(brief, '{relationship}', to_jsonb('{$relationship}'::text))")]);
        }

        DB::table('crowd_picks')
            ->where(function ($q) use ($gendered): void {
                foreach ($gendered as $value) {
                    $q->orWhere('context', "recipient:{$value}")
                        ->orWhere('context', 'like', "recipient:{$value}+%")
                        ->orWhere('context', 'like', "%+recipient:{$value}");
                }
            })
            ->delete();

        DB::table('feature_ideas')->where('seed_key', 'relations-by-gender')->delete();

        Schema::table('recipients', function (Blueprint $table): void {
            $table->string('gender', 6)->nullable();
        });

        DB::statement("ALTER TABLE recipients ADD CONSTRAINT recipients_gender_check CHECK (gender IS NULL OR gender IN ('male', 'female'))");

        // Your own, on My taste.
        Schema::table('user_tastes', function (Blueprint $table): void {
            $table->string('gender', 6)->nullable();
        });

        DB::statement("ALTER TABLE user_tastes ADD CONSTRAINT user_tastes_gender_check CHECK (gender IS NULL OR gender IN ('male', 'female'))");
    }

    public function down(): void
    {
        // Forward-only (CLAUDE.md).
    }
};
