<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * `brand` joins the Cove kinds.
 *
 * A Shop Cove and a Brand Cove are the same page shape — prose about an entity,
 * then that entity's products — and they were broken in opposite directions.
 * The shop page is a real Cove with authored prose and no products; the brand
 * page has the products (it is a search with the brand preselected) and nowhere
 * to put bespoke prose, because its copy comes from `copy_templates` slots that
 * are the same sentences for every brand.
 *
 * So Brand is brought onto Shop's model rather than the other way round.
 *
 * ## It renders on the page that already exists
 *
 * `/{market}/brand/{slug}` stays exactly where it is, and the Cove appears
 * above the grid. `docs/features/brand-pages.md` exists to argue for **one
 * canonical indexable URL per brand per market** — every brand mention on the
 * site points there — so a second address would split the link equity that page
 * was built to consolidate.
 *
 * Two CHECK constraints rather than a native enum, per CLAUDE.md: altering a PG
 * enum cannot run inside a transaction, which makes every future kind a deploy
 * hazard. A CHECK is simply replaced.
 */
return new class extends Migration
{
    private const KINDS = ['daily', 'persona', 'guide', 'seasonal', 'advice', 'shop', 'brand'];

    public function up(): void
    {
        $this->rewrite(self::KINDS);
    }

    public function down(): void
    {
        /*
         * Anything already written as a brand has to go before the constraint
         * narrows, or the rollback fails on rows the previous schema cannot
         * describe. Deleting is right here rather than reassigning: there is no
         * other kind a Brand Cove could honestly become.
         */
        DB::table('cove_plans')->where('kind', 'brand')->delete();
        DB::table('daily_pick_sets')->where('kind', 'brand')->delete();

        $this->rewrite(array_filter(self::KINDS, fn (string $k) => $k !== 'brand'));
    }

    /** @param list<string> $kinds */
    private function rewrite(array $kinds): void
    {
        $list = implode(', ', array_map(fn (string $k) => "'{$k}'", $kinds));

        foreach (['cove_plans', 'daily_pick_sets'] as $table) {
            DB::statement("ALTER TABLE {$table} DROP CONSTRAINT IF EXISTS {$table}_kind_check");
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$table}_kind_check CHECK (kind IN ({$list}))");
        }
    }
};
