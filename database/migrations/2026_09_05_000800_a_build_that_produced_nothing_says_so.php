<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When a build last ran and produced no page, and why.
 *
 * `buildArticle()` and `build()` already refuse to publish a page that cannot
 * clear its kind's floor — a three-item "best of" is a list with gaps and reads
 * as one. That refusal is correct and it was invisible: it logged a warning at
 * 06:00 and returned null, so an approved plan whose catalogue had gone thin
 * looked **exactly** like one whose date had not arrived yet, on every screen
 * and in every API response.
 *
 * For a person that is a page they think is coming and is not. For an
 * unattended run it is worse: the difference between *published* and *nothing
 * happened*, reported as neither.
 *
 * Two columns rather than a status, because the state is derived from what is
 * already true (see `App\Services\Cove\PlanState`) and a stored status would go
 * stale the moment somebody curated the plan from another screen. These record
 * an **event** — the last build that came to nothing — which does not go stale;
 * it is simply superseded when a later build works and clears it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cove_plans', function (Blueprint $table) {
            /*
             * Null once a build succeeds, so this is "the last attempt failed"
             * rather than "an attempt failed once". A plan that was thin in
             * March and published in April is not carrying a warning about
             * March.
             */
            $table->timestamp('last_build_failed_at')->nullable();

            /*
             * In the builder's words, and short.
             *
             * "3 of 5 products" is the whole of what an editor needs to fix it,
             * and it is the sentence that was going into a log file nobody
             * reads. Not an error class or a code: the reader of this column is
             * a person deciding whether to add two products or drop the plan.
             */
            $table->string('last_build_note', 300)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('cove_plans', function (Blueprint $table) {
            $table->dropColumn(['last_build_failed_at', 'last_build_note']);
        });
    }
};
