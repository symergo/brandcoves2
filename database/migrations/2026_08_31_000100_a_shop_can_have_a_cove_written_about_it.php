<?php

declare(strict_types=1);

use App\Enums\CoveKind;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A sixth Cove kind: `shop`.
 *
 * Every offer on this site names the shop it came from, and nothing anywhere
 * said a word about those shops. `/shops` shipped as a directory — names,
 * favicons, who is new — which answers "who do you compare" and not the half of
 * a buying decision a price comparison cannot answer: what this shop is like to
 * buy from. That is writing, so it is a Cove.
 *
 * ## Only the CHECK moves
 *
 * `daily_pick_sets` and `cove_plans` both constrain `kind` to a list generated
 * from {@see CoveKind::values()}, so this re-runs the same statements against
 * the widened enum. Nothing is backfilled and no column changes: a Shop Cove is
 * an ordinary undated row with a slug, which the existing address constraint
 * already permits — it says every non-daily kind has a slug and no drop date,
 * and it was written that way rather than as a list of kinds precisely so that
 * this migration would not have to touch it.
 *
 * Forward-only, like every migration here. `down()` narrows the list back, and
 * deletes any Shop Cove first for the same reason
 * `2026_08_30_000100_a_guide_is_a_cove` does: a CHECK cannot be added to a table
 * holding rows that violate it, so a rollback with content in the new kind would
 * fail halfway and leave the constraint dropped.
 *
 * String plus a CHECK rather than a native PG enum, per the conventions in
 * CLAUDE.md: `ALTER TYPE ... ADD VALUE` cannot run inside a transaction, which
 * would make every future kind a deploy hazard.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->constrain(CoveKind::values());
    }

    public function down(): void
    {
        DB::table('daily_pick_sets')->where('kind', CoveKind::Shop->value)->delete();
        DB::table('cove_plans')->where('kind', CoveKind::Shop->value)->delete();

        $this->constrain(array_values(array_filter(
            CoveKind::values(),
            fn (string $k) => $k !== CoveKind::Shop->value,
        )));
    }

    /** @param  list<string>  $kinds */
    private function constrain(array $kinds): void
    {
        $list = implode(', ', array_map(fn (string $k) => "'".$k."'", $kinds));

        foreach (['daily_pick_sets', 'cove_plans'] as $table) {
            DB::statement("ALTER TABLE {$table} DROP CONSTRAINT IF EXISTS {$table}_kind_check");
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$table}_kind_check CHECK (kind IN ({$list}))");
        }
    }
};
