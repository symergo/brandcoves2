<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The sentence printed under a product, separated from the reason it was chosen.
 *
 * `cove_plan_items.note` means "why a person put this on the list". It is a
 * brief for whoever writes the article and no reader ever sees it. That was the
 * whole distinction, and one endpoint quietly broke it: `POST /coves/{id}/
 * editorial` accepted `items[].copy` — an author's finished sentence about a
 * product — and wrote it into `note`, overwriting the curator's reasoning with
 * the prose it was supposed to produce.
 *
 * ## Why the copy had nowhere else to go
 *
 * `EditionBuilder::itemCopy()` reads each card's sentence out of `$written`, the
 * model's own output, and nowhere else. So when a plan carries authored prose
 * the builder short-circuits, `$written` comes back empty, and every
 * `daily_picks.blurb` on the edition is null — an article written entirely by
 * hand publishes with blank cards under paragraphs that discuss them.
 *
 * A column, not a reused one. The two fields answer different questions, they
 * are written by different people at different times, and the failure of
 * merging them is silent in both directions: prose overwrites a brief, or a
 * brief is printed to a reader as though it were copy.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cove_plan_items', function (Blueprint $table) {
            /*
             * Nullable, and null is the ordinary state.
             *
             * Most plans are written by the builder, which supplies this at
             * build time and never needs it stored. It is filled only when a
             * person or an external author writes the card copy themselves.
             *
             * Length matches the `items.*.copy` validation on the editorial API
             * (500) rather than being unbounded: it is one sentence under a
             * product card, and something long enough to be a paragraph belongs
             * in the article.
             */
            $table->string('copy', 500)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('cove_plan_items', function (Blueprint $table) {
            $table->dropColumn('copy');
        });
    }
};
