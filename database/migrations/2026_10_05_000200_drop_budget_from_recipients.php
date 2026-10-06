<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Contract: a person no longer has a budget; their lists do (owner,
 * 2026-10-05: "uit de code en db halen bij personen"). The release before this
 * one copied every budget onto that person's lists and stopped reading these
 * columns, so they hold nothing anybody sees.
 *
 * Ship this in a deploy AFTER `2026_10_05_000100_add_budget_to_wishlists` is
 * live: dropped in the same deploy, a rollback would put back code that still
 * reads them. See docs/features/list-budget.md.
 *
 * down() gives the columns back empty; what they held is on the lists.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('recipients', function (Blueprint $table): void {
            $table->dropColumn(['budget_min', 'budget_max']);
        });
    }

    public function down(): void
    {
        Schema::table('recipients', function (Blueprint $table): void {
            $table->integer('budget_min')->nullable();
            $table->integer('budget_max')->nullable();
        });
    }
};
