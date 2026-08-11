<?php

declare(strict_types=1);

use App\Enums\Market;
use App\Enums\Source;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What people actually buy, and how that moves.
 *
 * The site has had no demand signal of its own: guide topics come from a search
 * log that is nearly empty on a new site, and the `trends` mode measures
 * "recently added" and "more shops stocked it", neither of which is demand. A
 * retailer's bestseller chart is demand, measured by someone with millions of
 * transactions.
 *
 * See docs/features/popularity-charts.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        /**
         * The categories a source charts separately.
         *
         * Also the crawl frontier: bol publishes no list of category ids
         * anywhere, so the only way to learn one is to pull a chart and read the
         * relevant categories off the response. Rows arrive by discovery, not by
         * configuration.
         */
        Schema::create('chart_categories', function (Blueprint $table) {
            $table->id();
            $table->string('source');
            $table->string('market');
            // The source's own id, opaque to us.
            $table->string('external_id');
            $table->string('name');
            /*
             * Folded in PHP with Str::slug(), never in SQL.
             *
             * The same reasoning as brand_stats: Postgres cannot reproduce it,
             * because Str::slug() transliterates ("Kärcher" → "karcher") where
             * lower(replace(...)) does not. A slug computed two ways is two
             * slugs.
             */
            $table->string('slug');
            $table->string('parent_external_id')->nullable();
            // 0 is the market-wide chart's children; the crawl is bounded on it.
            $table->smallInteger('depth')->default(0);
            $table->integer('product_count')->nullable();
            /*
             * An operator's off switch, not a discovery flag.
             *
             * Some categories are worthless to us — gift cards, digital
             * downloads, subscriptions — and the cheapest fix is to stop pulling
             * them rather than to filter their products downstream forever.
             */
            $table->boolean('enabled')->default(true);
            $table->timestampTz('last_pulled_at')->nullable();
            $table->timestampTz('first_seen_at')->useCurrent();
            $table->timestamps();

            $table->unique(['source', 'market', 'external_id']);
            // The puller's work-list query: never-pulled first, then stalest.
            $table->index(['source', 'market', 'enabled', 'last_pulled_at']);
            $table->unique(['source', 'market', 'slug']);
        });

        /**
         * One chart position, one day.
         *
         * DECISION-ONLY: an external id, a position and a date. No title, price
         * or image, and that is deliberate rather than minimal — it is what makes
         * this table safe for Amazon, which permits storing which product was
         * chosen and forbids mirroring the catalogue entry. bol's product data
         * lives in `products`, where Source::allowsCatalogueStorage() permits it.
         *
         * The history is the point. A single snapshot is a list; two snapshots a
         * week apart are a trend, and rank movement is what the discovery ranker
         * reads as novelty.
         */
        Schema::create('popular_ranks', function (Blueprint $table) {
            $table->id();
            $table->string('source');
            $table->string('market');
            /*
             * '*' is the market-wide chart, NOT null.
             *
             * A nullable column cannot carry the unique key below: Postgres
             * treats NULLs as distinct, so every daily pull of the overall chart
             * would insert a duplicate instead of upserting, and the table would
             * grow a new copy of the chart every single day.
             */
            $table->string('category_external_id')->default('*');
            $table->string('external_id');
            $table->smallInteger('rank');
            $table->date('captured_on');
            $table->timestampTz('captured_at');
            /*
             * Resolved after grouping, not at write time.
             *
             * The chart's products are upserted in the same run, but they only
             * acquire a group once GroupProducts has run — so this starts null
             * and is filled in by the same job on its next pass. Nullable also
             * covers the honest case: an entry we could not group at all.
             */
            $table->foreignId('group_id')->nullable()->constrained('product_groups')->nullOnDelete();
            $table->timestamps();

            // One sample per chart per day, on the same reasoning as
            // price_history: the puller may run twice, the scheduler retries,
            // and an operator will run it by hand.
            $table->unique(['source', 'market', 'category_external_id', 'external_id', 'captured_on'], 'popular_ranks_daily_unique');
            // Reading one chart back in rank order — the retriever's query.
            $table->index(['source', 'market', 'category_external_id', 'captured_on', 'rank'], 'popular_ranks_chart_index');
            // Movement for one product across snapshots.
            $table->index(['group_id', 'captured_on']);
        });

        $this->addChecks();
    }

    private function addChecks(): void
    {
        $markets = $this->quoted(Market::values());
        $sources = $this->quoted(Source::values());

        // String + CHECK rather than a native PG enum: altering an enum cannot
        // run inside a transaction, which makes every future value a deploy
        // hazard. See CLAUDE.md.
        DB::statement("ALTER TABLE chart_categories ADD CONSTRAINT chart_categories_source_check CHECK (source IN ($sources))");
        DB::statement("ALTER TABLE chart_categories ADD CONSTRAINT chart_categories_market_check CHECK (market IN ($markets))");
        DB::statement("ALTER TABLE popular_ranks ADD CONSTRAINT popular_ranks_source_check CHECK (source IN ($sources))");
        DB::statement("ALTER TABLE popular_ranks ADD CONSTRAINT popular_ranks_market_check CHECK (market IN ($markets))");
        // Rank 1 is the top. A zero or negative rank is a normalisation bug, and
        // the log-decay scoring divides by it.
        DB::statement('ALTER TABLE popular_ranks ADD CONSTRAINT popular_ranks_rank_check CHECK (rank > 0)');
    }

    /** @param list<string> $values */
    private function quoted(array $values): string
    {
        return implode(', ', array_map(fn (string $v) => "'".$v."'", $values));
    }

    public function down(): void
    {
        Schema::dropIfExists('popular_ranks');
        Schema::dropIfExists('chart_categories');
    }
};
