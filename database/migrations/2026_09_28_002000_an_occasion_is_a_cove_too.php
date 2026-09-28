<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * `occasion` joins the Cove kinds (owner, 2026-09-28).
 *
 * Gifts for Moederdag, a housewarming, a retirement: the same page as a
 * persona, but not a persona, because an occasion is not a kind of person.
 * It is read at `/{market}/gift-ideas/occasion/{slug}` and has its own row on
 * the gift-ideas page. See docs/features/occasion-coves.md.
 *
 * The two CHECK constraints are replaced, as for `brand` before it: a CHECK
 * rather than a native enum is what makes a new kind safe to deploy (CLAUDE.md).
 * Replacing a CHECK validates the rows already there, all of which hold one of
 * the old kinds, so it cannot fail on production data.
 */
return new class extends Migration
{
    private const KINDS = ['daily', 'persona', 'guide', 'seasonal', 'advice', 'shop', 'brand', 'occasion'];

    public function up(): void
    {
        $this->rewrite(self::KINDS);
    }

    public function down(): void
    {
        // Occasion rows cannot become another kind honestly, so they go
        // before the constraint narrows, or the rollback fails on them.
        DB::table('cove_plans')->where('kind', 'occasion')->delete();
        DB::table('daily_pick_sets')->where('kind', 'occasion')->delete();

        $this->rewrite(array_values(array_filter(self::KINDS, fn (string $k) => $k !== 'occasion')));
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
