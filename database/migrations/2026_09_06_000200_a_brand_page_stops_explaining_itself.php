<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The six generated sections come off the brand page.
 *
 * ## What they were
 *
 * `brand.below_grid` shipped with six headed sections per language — *Over
 * Samsung*, *Waar Samsung verkocht wordt*, *Een product van Samsung kiezen*,
 * *Wat kosten Samsung-producten?*, *Welke winkels verkopen Samsung?*, *Is
 * Samsung nu in de aanbieding?* — every clause assembled from the numbers in the
 * grid above them, and identical in shape on every brand page in the market.
 *
 * They were checkable, which is why they were publishable at all. They were
 * never *worth reading*, which is a different test and the one that decides
 * whether a page should carry them.
 *
 * ## Why now
 *
 * The brand page has just split in two. Where somebody has written a Brand Cove
 * the writing is the page; where nobody has, the page is a search filtered to
 * one brand — and a filtered search is what it should look like, rather than a
 * filtered search wearing six paragraphs of arithmetic as an article.
 *
 * So this is not "the templated copy loses to the Cove". It is that the fallback
 * is a listing, and a listing does not need prose to justify itself.
 *
 * ## The region survives; only the content goes
 *
 * `brand.below_grid` is still registered, still editable, and now ships empty
 * like `brand.above_grid` beside it. Removing the *place* would take away
 * somebody's ability to put a real sentence there without a deploy, which is the
 * whole point of the page-template system. Removing the *text* is the decision
 * that was actually made.
 *
 * The words are not lost either: they are still in
 * `database/migrations/data/page-blocks-2026-09.php`, which is where they were
 * seeded from, so restoring them is a copy rather than an archaeology exercise.
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
         * Scoped to the region, never `truncate`.
         *
         * `page_blocks` also holds the search page's copy and the empty states,
         * which nothing here is deciding about — and by the time this runs on
         * production an editor may have written blocks of their own. Deleting by
         * page and region is what keeps this a decision about six sections
         * rather than about the table.
         */
        $removed = DB::table('page_blocks')
            ->where('page', 'brand')
            ->where('region', 'below_grid')
            ->delete();

        Log::info('Brand page: removed the generated sections below the grid', ['blocks' => $removed]);
    }

    /**
     * Forward-only, like every migration here.
     *
     * Restoring is deliberate rather than automatic: the rows are in the seed
     * data file, and somebody putting them back should be choosing to, not
     * discovering it as the side effect of a rollback.
     */
    public function down(): void
    {
        throw new RuntimeException(
            'Forward-only. The generated brand sections are in '
            .'database/migrations/data/page-blocks-2026-09.php if they are wanted back.'
        );
    }
};
