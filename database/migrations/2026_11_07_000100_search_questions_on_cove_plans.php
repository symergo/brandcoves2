<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The questions people search around an article's subject (owner, 2026-11-07).
 *
 * "Hoe lang heb ik om iets terug te sturen?", "wie betaalt de retourkosten?":
 * the advice writer turns them into the article's section headings and its
 * FAQ, next to the main keyword it already had (`focus_keyphrase`).
 *
 * Not `queries`, which already exists and means something else: the product
 * search terms that fill a Cove's shortlist, sixty characters each. A question
 * is a sentence, and an advice article has no shortlist to fill.
 *
 * Nullable and additive, so the running code never notices it (expand only).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cove_plans', function (Blueprint $table): void {
            $table->jsonb('search_questions')->nullable();
        });
    }
};
