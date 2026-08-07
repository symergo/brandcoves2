<?php

declare(strict_types=1);

use App\Enums\Market;
use App\Enums\PublishStatus;
use App\Enums\Reaction;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /**
         * One day's themed set.
         *
         * The theme is what turns a product firehose into an editorial column —
         * it makes the set cohere and it is the reason to come back tomorrow.
         */
        Schema::create('daily_pick_sets', function (Blueprint $table) {
            $table->id();
            $table->string('market');
            // The day this set is *for*, in site-local time.
            $table->date('drop_date');
            $table->string('theme_title');
            $table->text('theme_blurb')->nullable();
            $table->string('theme_slug');
            // Whether the theme came from the AI or the curated fallback rotation.
            $table->string('theme_source')->default('curated');
            $table->string('status')->default(PublishStatus::Draft->value);
            $table->timestampTz('published_at')->nullable();
            $table->timestamps();

            $table->unique(['market', 'drop_date']);
            $table->index(['market', 'status', 'published_at']);
        });

        Schema::create('daily_picks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('set_id')->constrained('daily_pick_sets')->cascadeOnDelete();
            $table->foreignId('group_id')->nullable()->constrained('product_groups')->nullOnDelete();

            // Amazon may not be mirrored, so for an Amazon pick we store the
            // DECISION, not the catalogue: the ASIN plus the scoring metadata
            // that chose it. Title, image, price and availability are re-fetched
            // live at render, and a failed fetch hides the pick rather than
            // showing stale Amazon data.
            $table->string('amazon_asin')->nullable();

            $table->smallInteger('rank');
            $table->string('slug');
            // Written by a queued job. Never generated on a page view.
            $table->text('blurb')->nullable();
            $table->float('surprise_score')->nullable();
            $table->jsonb('score_breakdown')->nullable();
            // Set for picks that came from the high-discount lane rather than surprise.
            $table->smallInteger('discount_percent')->nullable();

            $table->integer('mindblown_count')->default(0);
            $table->integer('meh_count')->default(0);
            $table->timestamps();

            $table->unique(['set_id', 'rank']);
            $table->index('slug');
            // The rolling "already featured" memory, so today never repeats last month.
            $table->index(['group_id', 'created_at']);
        });

        Schema::create('pick_reactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pick_id')->constrained('daily_picks')->cascadeOnDelete();
            // Hashed cookie identity — one reaction per visitor per pick, no
            // account needed. This is a visitor write, so it stays AI-free and
            // rate-limited: an event insert and nothing more.
            $table->string('identity_hash');
            $table->string('reaction');
            $table->timestamps();

            $table->unique(['pick_id', 'identity_hash']);
        });

        // Themes already used, so the generator can be told what to avoid. Kept
        // separately from daily_pick_sets because a rejected draft theme still
        // counts as "recently seen" for diversity purposes.
        Schema::create('used_themes', function (Blueprint $table) {
            $table->id();
            $table->string('market');
            $table->string('theme_slug');
            $table->date('used_on');
            $table->timestamps();

            $table->index(['market', 'used_on']);
        });

        /**
         * Buying guides, generated from search_log — the site's own demand
         * signal. Writing about what people actually search for here is what
         * makes them rank.
         */
        Schema::create('guides', function (Blueprint $table) {
            $table->id();
            $table->string('market');
            $table->string('slug');
            $table->string('title');
            $table->text('intro')->nullable();
            // "How to choose" and any additional editorial copy, as Markdown.
            $table->text('body_md')->nullable();
            // The clustered search queries that justified this guide.
            $table->jsonb('source_queries')->default(DB::raw("'[]'::jsonb"));
            // Total 30-day search volume of the cluster at generation time.
            $table->integer('source_volume')->default(0);

            $table->string('meta_description')->nullable();
            $table->string('focus_keyphrase')->nullable();
            // Q&A pairs rendered as FAQPage JSON-LD.
            $table->jsonb('faq')->nullable();

            $table->string('status')->default(PublishStatus::Draft->value);
            $table->timestampTz('published_at')->nullable();
            // Guides go stale when their products do; a monthly job re-checks them.
            $table->timestampTz('last_checked_at')->nullable();
            $table->timestamps();

            $table->unique(['market', 'slug']);
            $table->index(['market', 'status', 'published_at']);
        });

        Schema::create('guide_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('guide_id')->constrained()->cascadeOnDelete();
            $table->foreignId('group_id')->constrained('product_groups')->cascadeOnDelete();
            $table->smallInteger('rank');
            // The one thing the AI writes per item. It never invents products,
            // prices or links — those come from the structured context we build.
            $table->text('editorial_copy')->nullable();
            // A short "best for X" label.
            $table->string('verdict')->nullable();
            // Set when the monthly freshness check finds the group out of stock.
            $table->boolean('unavailable')->default(false);
            $table->timestamps();

            $table->unique(['guide_id', 'rank']);
            $table->index('group_id');
        });

        /**
         * Candidate guide topics, ranked. Surfaced in admin so a human can
         * queue, edit or reject one before anything is generated.
         */
        Schema::create('guide_topics', function (Blueprint $table) {
            $table->id();
            $table->string('market');
            $table->string('topic');
            $table->jsonb('member_queries')->default(DB::raw("'[]'::jsonb"));
            $table->integer('search_volume')->default(0);
            // How many in-stock groups we can actually build a guide from. A
            // high-volume topic with no products is not a guide, it is a gap.
            $table->integer('available_products')->default(0);
            $table->float('score')->default(0);
            $table->string('status')->default('candidate');
            $table->foreignId('guide_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            $table->unique(['market', 'topic']);
            $table->index(['market', 'status', 'score']);
        });

        /**
         * Interest/vibe → concrete catalogue queries.
         *
         * Widened offline by a queued job; the request path only ever READS this.
         * That is what keeps a visitor request from triggering AI spend.
         */
        Schema::create('gift_angles', function (Blueprint $table) {
            $table->id();
            $table->string('market');
            $table->string('interest');
            $table->string('vibe')->nullable();
            $table->jsonb('queries')->default(DB::raw("'[]'::jsonb"));
            $table->string('source')->default('curated');
            $table->timestamps();

            $table->index(['market', 'interest']);
        });

        $this->addChecks();
    }

    private function addChecks(): void
    {
        $markets = $this->quoted(Market::values());
        $publish = $this->quoted(PublishStatus::values());

        foreach (['daily_pick_sets', 'used_themes', 'guides', 'guide_topics', 'gift_angles'] as $table) {
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$table}_market_check CHECK (market IN ($markets))");
        }

        DB::statement("ALTER TABLE daily_pick_sets ADD CONSTRAINT daily_pick_sets_status_check CHECK (status IN ($publish))");
        DB::statement("ALTER TABLE guides ADD CONSTRAINT guides_status_check CHECK (status IN ($publish))");
        DB::statement('ALTER TABLE pick_reactions ADD CONSTRAINT pick_reactions_reaction_check CHECK (reaction IN ('.$this->quoted(Reaction::values()).'))');

        // A pick points at either a stored group or a live-rendered Amazon ASIN,
        // never both and never neither.
        DB::statement('ALTER TABLE daily_picks ADD CONSTRAINT daily_picks_one_target CHECK (num_nonnulls(group_id, amazon_asin) = 1)');

        // Only one interest/vibe angle row per market. A null vibe is the
        // "any vibe" default, and NULLS NOT DISTINCT makes the unique index
        // actually catch a duplicate default (plain UNIQUE would not).
        DB::statement('CREATE UNIQUE INDEX gift_angles_unique_idx ON gift_angles (market, interest, vibe) NULLS NOT DISTINCT');
    }

    /** @param list<string> $values */
    private function quoted(array $values): string
    {
        return implode(', ', array_map(fn (string $v) => "'".$v."'", $values));
    }

    public function down(): void
    {
        Schema::dropIfExists('gift_angles');
        Schema::dropIfExists('guide_topics');
        Schema::dropIfExists('guide_items');
        Schema::dropIfExists('guides');
        Schema::dropIfExists('used_themes');
        Schema::dropIfExists('pick_reactions');
        Schema::dropIfExists('daily_picks');
        Schema::dropIfExists('daily_pick_sets');
    }
};
