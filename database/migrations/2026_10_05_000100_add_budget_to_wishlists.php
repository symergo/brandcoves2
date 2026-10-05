<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * A budget belongs to a list, not to a person (owner, 2026-10-05): what you
 * spend on Mama for her birthday and for Christmas are two lists and two
 * budgets, and she is one person.
 *
 * Expand: the list gains the columns, and every list about a saved person
 * takes that person's budget. A person with a budget and no list keeps none:
 * there is nowhere to put it (docs/features/list-budget.md). The person's
 * columns stay, read by nothing, until a later release drops them (contract).
 * Cents, like every amount here (invariant 7).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wishlists', function (Blueprint $table): void {
            $table->integer('budget_min')->nullable();
            $table->integer('budget_max')->nullable();
        });

        DB::statement(<<<'SQL'
            UPDATE wishlists AS w
               SET budget_min = r.budget_min,
                   budget_max = r.budget_max
              FROM recipients AS r
             WHERE w.recipient_id = r.id
               AND w.kind = 'for_someone'
               AND (r.budget_min IS NOT NULL OR r.budget_max IS NOT NULL)
            SQL);
    }

    public function down(): void
    {
        Schema::table('wishlists', function (Blueprint $table): void {
            $table->dropColumn(['budget_min', 'budget_max']);
        });
    }
};
