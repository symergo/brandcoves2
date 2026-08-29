<?php

declare(strict_types=1);

use App\Models\CovePlan;
use App\Models\DailyPickSet;
use Illuminate\Database\Migrations\Migration;

/**
 * A plan behind every Cove that was ever published.
 *
 * The planner could only ever describe the future. A Daily built by the 06:00
 * job, and a guide published by the topic queue, left no plan behind — so the
 * editorial table was full of pages with no record of what they were for, and
 * "open this one and re-curate it" was not something you could do to most of the
 * site. Which is most of the site: almost nothing live was planned by hand.
 *
 * This mints the missing rows. `status = 'used'` throughout, because a record of
 * what happened is not an instruction — see `CovePlan::recordFor()` for why
 * marking them `approved` would turn the machine's own output into an editorial
 * decision that the next rebuild obeys.
 *
 * ## What it deliberately does not copy
 *
 * **The products.** It is tempting to seed each plan's shortlist from the picks
 * that were published, so a curator opening one sees the page as it stands. It
 * would be wrong twice over.
 *
 * A curated item *leads* the edition and is exempt from the ninety-day repeat
 * memory, and a locked plan is the edition outright. Backfilling picks into
 * `cove_plan_items` would therefore convert every automatic Daily into a curated
 * one, and the next routine rebuild — a scheduler retry, a redeploy — would
 * republish exactly the products it was supposed to refresh.
 *
 * And `cove_plan_items.note` exists to hold *why a person chose this*. Nobody
 * chose these; a ranker did. `PlanCurator::prefill()` already refuses to write a
 * note for a machine's suggestion for the same reason, and claiming curation
 * that never happened is worse than an empty list. The curation screen fills a
 * shortlist from the engine in one click, so an editor re-curating an old Cove
 * is no worse off.
 */
return new class extends Migration
{
    public function up(): void
    {
        DailyPickSet::query()
            ->whereDoesntHave('plan')
            ->orderBy('id')
            // Chunked because this walks every edition the site has ever
            // published, and a deploy that loads them all into memory at once
            // is a deploy that dies on the largest market.
            ->chunkById(200, function ($editions): void {
                foreach ($editions as $edition) {
                    CovePlan::recordFor($edition);
                }
            });
    }

    public function down(): void
    {
        /*
         * Only the ones this created.
         *
         * The note is the marker, which is unlovely but honest: there is no
         * other column that separates "minted from a build" from "written by a
         * person and since used", and deleting the second kind would throw away
         * editorial work to undo a bookkeeping migration.
         */
        CovePlan::query()
            ->where('status', 'used')
            ->where('note', 'Recorded automatically from the build. Nobody planned this one.')
            ->delete();
    }
};
