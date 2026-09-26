<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The gift landing pages that are worth having (roadmap step 4, part 2;
 * docs/features/gift-landing-pages.md).
 *
 * One row per page that exists: "gifts for a father who loves cooking" in
 * be-nl, recorded nightly by PlanGiftLandingPages when the catalogue holds at
 * least eight products for it. A page with no row answers 404, which is what
 * keeps hundreds of thin combinations out of the index.
 *
 * `interest` is null for the recipient's own page (`/for/papa`). The unique
 * index treats that null as a value (NULLS NOT DISTINCT, Postgres 15+), so a
 * recipient has one such page per market rather than one per nightly run.
 *
 * No CHECK on `recipient` or `interest`: both are written only by the job,
 * from the enums, and a CHECK would turn every new interest into a migration
 * the job then fails without. A new table, so nothing here locks anything.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gift_landings', function (Blueprint $table) {
            $table->id();
            $table->string('market', 8);
            // RecipientType value: father, mother, sibling…
            $table->string('recipient', 20);
            // Interest value, or null for the recipient's own page.
            $table->string('interest', 20)->nullable();
            // The canonical path in this market's words, for the sitemap.
            $table->string('path', 160);
            // The brief the page is rendered from (TasteBrief::toArray()).
            $table->jsonb('brief');
            // Distinct products that answered it the last time it was checked.
            $table->unsignedSmallInteger('product_count');
            $table->timestamp('checked_at');
            $table->timestamps();

            $table->unique(['market', 'path']);
            $table->index(['market', 'interest']);
        });

        DB::statement('CREATE UNIQUE INDEX gift_landings_market_recipient_interest_unique ON gift_landings (market, recipient, interest) NULLS NOT DISTINCT');
    }

    public function down(): void
    {
        Schema::dropIfExists('gift_landings');
    }
};
