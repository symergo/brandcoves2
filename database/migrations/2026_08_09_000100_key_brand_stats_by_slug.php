<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;

/**
 * One brand_stats row per SLUG, not per feed spelling.
 *
 * ## What went wrong
 *
 * The first refresh against the real catalogue failed on
 * `(be-nl, audio-technica) already exists`. Two rows, one slug: an Awin feed
 * calls it "Audio-Technica" and bol calls it "Audio Technica", and `Str::slug()`
 * correctly folds both to the same thing.
 *
 * The unique index did its job — it turned a silent wrong answer into a loud
 * failure on the first run. The question is what the right answer is.
 *
 * ## Why not disambiguate the slugs
 *
 * The obvious fix is to let the larger spelling keep `audio-technica` and give
 * the other `audio-technica-2`. That produces two pages that are each half a
 * brand, one of which nobody will ever link to, and it makes the URL depend on
 * which spelling happened to have more products this week — so it changes when
 * a feed does, and every inbound link to the loser breaks.
 *
 * ## Why not normalise the feed
 *
 * Rewriting `product_groups.brand` at ingestion is tempting and wrong: the feed
 * value is what the merchant said, it is what the search index is built from,
 * and picking a winner throws away the ability to tell which merchant spells it
 * which way. Ingestion should record; presentation should decide.
 *
 * ## What this does instead
 *
 * The **slug is the identity** and the spellings are aliases of it. One row per
 * (market, slug), holding the most-stocked spelling as the display name and the
 * rest in `aliases` — and the brand page filters on all of them, so
 * `/brand/audio-technica` shows every Audio-Technica product however the shop
 * that listed it chose to punctuate.
 *
 * That is strictly more correct than the naive version rather than a workaround
 * for it: a reader searching for a brand does not care about a hyphen, and a
 * comparison site that shows them half the offers because two feeds disagree
 * about punctuation has failed at its one job.
 *
 * The table is derived and recomputed nightly, so it is truncated here rather
 * than migrated. Rebuilding it costs one command.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Derived data. The next refresh rebuilds it, and keeping rows whose key
        // is about to change means resolving conflicts in a migration.
        DB::statement('TRUNCATE TABLE brand_stats');

        Schema::table('brand_stats', function (Blueprint $table) {
            /*
             * Every spelling that folds to this slug, including the canonical
             * one. The brand page filters on this array, which is why it must
             * contain the display name too — a filter built from "the aliases"
             * that excluded the main spelling would show everything except the
             * products people actually came for.
             */
            $table->jsonb('aliases')->default(DB::raw("'[]'::jsonb"));
        });

        // The slug is now the key, so it cannot be null and the partial index is
        // no longer the right shape.
        DB::statement('DROP INDEX IF EXISTS brand_stats_market_slug_idx');
        DB::statement('ALTER TABLE brand_stats ALTER COLUMN slug SET NOT NULL');

        // The old key. `brand` is now a display name that may change spelling
        // between runs; keying an upsert on it would insert a second row for the
        // same slug the first time a feed's punctuation shifted.
        DB::statement('ALTER TABLE brand_stats DROP CONSTRAINT IF EXISTS brand_stats_market_brand_unique');

        DB::statement('ALTER TABLE brand_stats ADD CONSTRAINT brand_stats_market_slug_unique UNIQUE (market, slug)');
    }

    public function down(): void
    {
        DB::statement('TRUNCATE TABLE brand_stats');
        DB::statement('ALTER TABLE brand_stats DROP CONSTRAINT IF EXISTS brand_stats_market_slug_unique');
        DB::statement('ALTER TABLE brand_stats ALTER COLUMN slug DROP NOT NULL');
        DB::statement('CREATE UNIQUE INDEX brand_stats_market_slug_idx ON brand_stats (market, slug) WHERE slug IS NOT NULL');
        DB::statement('ALTER TABLE brand_stats ADD CONSTRAINT brand_stats_market_brand_unique UNIQUE (market, brand)');

        Schema::table('brand_stats', function (Blueprint $table) {
            $table->dropColumn('aliases');
        });
    }
};
