<?php

declare(strict_types=1);

use App\Enums\CoveKind;
use App\Services\Content\GuideFold;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A guide is a Cove. One editorial table, five kinds.
 *
 * Until now the site published editorial through two pipelines that shared
 * nothing. A Cove was *planned* — `cove_plans` records what the page is for, a
 * curated shortlist with a note against each product, and a brief for the
 * writer — and then built. A guide was not planned at all: `GuideBuilder` chose
 * its own products, wrote its own prose and published a `guides` row, and the
 * only human control was editing the sentences afterwards.
 *
 * They are the same job. Decide what a page is about, choose the products, brief
 * the writer, publish. Keeping two tables meant keeping two answers to every
 * question that followed — two admin screens, two preview mechanisms, two
 * exports, two ways to be unavailable — and only one of them let a person do
 * the deciding.
 *
 * So `guides` and `guide_items` fold into `daily_pick_sets` and `daily_picks`,
 * and the kind says which sort of page it is:
 *
 *   daily     one morning's edition, addressed by its date
 *   persona   a gift persona, permanent, addressed by a slug
 *   guide     a buying guide — a ranked shortlist, "the five best X"
 *   seasonal  a guide whose demand has a window: Halloween, Black Friday
 *   advice    an article with no shortlist at all, where the prose IS the
 *             substance ("how to read a returns policy")
 *
 * ## What does not change
 *
 * **Every URL.** `/{market}/guides/{slug}` still resolves, still appears in the
 * sitemap with the same lastmod, still pairs across markets in hreflang, and the
 * `magazine`/`articles` legacy redirects still land on it. A fold that silently
 * re-addressed a hundred indexed pages would cost more than it saved.
 *
 * ## Expand only
 *
 * `guides`, `guide_items`, `daily_pick_sets.guide_id` and
 * `guide_topics.guide_id` all survive this migration untouched. Nothing is
 * dropped until the readers are gone, so a rollback never meets a schema the
 * previous image cannot read.
 *
 * ## The one behaviour this changes
 *
 * A persona and a guide in the same market can no longer share a slug. The
 * partial unique index on (market, slug) already existed for personas and now
 * covers every dateless kind. They live at different paths — `/gift-ideas/x`
 * and `/guides/x` — so a collision would be legal in URL terms, but one slug
 * namespace per market is the simpler rule and it is what keeps
 * `[[guide:slug]]` unambiguous about which page it means.
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
         * Columns a guide has and an edition did not.
         *
         * `body` rather than `body_md`: the column held Markdown in name only.
         * It is rendered as plain paragraphs, deliberately — the copy comes from
         * a language model and the one thing you never do with model output is
         * hand it to something that interprets markup.
         */
        Schema::table('daily_pick_sets', function (Blueprint $table) {
            $table->text('body')->nullable()->after('editorial_source');
            $table->jsonb('faq')->nullable()->after('body');
            $table->string('meta_description')->nullable()->after('faq');
            $table->string('focus_keyphrase')->nullable()->after('meta_description');

            // Why this page exists, and a fact no competitor has: the 30-day
            // search volume measured on this site when it was written.
            $table->jsonb('source_queries')->default(DB::raw("'[]'::jsonb"))->after('focus_keyphrase');
            $table->integer('source_volume')->default(0)->after('source_queries');

            // Guides decay — products sell out and feeds drop them — so a
            // monthly pass re-checks anything older than the refresh window.
            $table->timestampTz('last_checked_at')->nullable()->after('published_at');

            /*
             * A seasonal Cove's window, as MM-DD.
             *
             * Year-less because the window recurs, and a window whose `to` is
             * before its `from` wraps the year (Valentine's opens 12-27). Same
             * encoding as `guide_topics.season_from`, which is where these are
             * copied from.
             */
            $table->string('season_from', 5)->nullable()->after('last_checked_at');
            $table->string('season_to', 5)->nullable()->after('season_from');

            /*
             * The Cove this edition points its readers at, replacing `guide_id`.
             *
             * A Daily Cove has always featured a guide at the foot of the page.
             * Now that a guide is a Cove, that link is a self-reference rather
             * than a foreign one. `guide_id` stays until nothing reads it.
             */
            $table->foreignId('featured_cove_id')->nullable()->after('guide_id')
                ->constrained('daily_pick_sets')->nullOnDelete();

            /*
             * Where a folded row came from, and the reason the fold is
             * re-runnable rather than a one-shot.
             *
             * No foreign key: a constraint pointing at the table this change
             * exists to delete would have to be dropped before the drop anyway,
             * and the column outliving its target by a single migration is
             * exactly what expand/contract is. Dropped with `guides` in the
             * contract migration.
             */
            $table->unsignedBigInteger('folded_from_guide_id')->nullable();
            $table->unique('folded_from_guide_id');
        });

        Schema::table('daily_picks', function (Blueprint $table) {
            // A short "best for X". `guide_items.verdict`.
            $table->string('verdict')->nullable()->after('blurb');

            /*
             * Dimmed, not hidden.
             *
             * A Daily Cove hides a find that has gone out of stock: the page is
             * a snapshot of one morning and a gap is better than a dead card. A
             * guide *dims* it, because the guide is an argument about what to
             * buy and silently removing the entry it argued for leaves a piece
             * of reasoning with a hole in it.
             */
            $table->boolean('unavailable')->default(false)->after('verdict');
        });

        /*
         * The plan side of the same five kinds.
         *
         * A plan carries the article fields too, because the point of the
         * planner is that a person can decide them before anything is built —
         * the keyphrase a guide is written to hit, the FAQ it should answer,
         * the "how to choose" section. Left null, the builder writes them.
         */
        Schema::table('cove_plans', function (Blueprint $table) {
            $table->text('body')->nullable()->after('editorial');
            $table->jsonb('faq')->nullable()->after('body');
            $table->string('meta_description')->nullable()->after('faq');
            $table->string('focus_keyphrase')->nullable()->after('meta_description');
            $table->string('season_from', 5)->nullable()->after('drop_date');
            $table->string('season_to', 5)->nullable()->after('season_from');
        });

        /*
         * The kind lists widen, and the address rule generalises.
         *
         * String plus a CHECK, never a native PG enum: altering one cannot run
         * inside a transaction, which makes every future value a deploy hazard —
         * and this migration is the proof, because it adds three.
         */
        $kinds = implode(', ', array_map(
            fn (string $k) => "'".$k."'",
            CoveKind::values(),
        ));

        DB::statement('ALTER TABLE daily_pick_sets DROP CONSTRAINT IF EXISTS daily_pick_sets_kind_check');
        DB::statement("ALTER TABLE daily_pick_sets ADD CONSTRAINT daily_pick_sets_kind_check CHECK (kind IN ({$kinds}))");

        DB::statement('ALTER TABLE cove_plans DROP CONSTRAINT IF EXISTS cove_plans_kind_check');
        DB::statement("ALTER TABLE cove_plans ADD CONSTRAINT cove_plans_kind_check CHECK (kind IN ({$kinds}))");

        /*
         * Exactly one address, and only a Daily is addressed by a date.
         *
         * This is load-bearing rather than tidy. `CovePlan::approvedFor()` and
         * the 06:00 build both match on (market, drop_date); a guide that
         * quietly acquired a date would be picked up and published as that
         * morning's Daily Cove, with no symptom anywhere until a reader saw it.
         */
        DB::statement('ALTER TABLE daily_pick_sets DROP CONSTRAINT IF EXISTS daily_pick_sets_address_check');
        DB::statement(
            'ALTER TABLE daily_pick_sets ADD CONSTRAINT daily_pick_sets_address_check CHECK ('.
            "(kind = 'daily' AND drop_date IS NOT NULL AND slug IS NULL)".
            " OR (kind <> 'daily' AND drop_date IS NULL AND slug IS NOT NULL))"
        );

        /*
         * A plan is held to the dating rule but not to the slug rule.
         *
         * A slug is chosen when the page is named, and a plan exists to be
         * thought about before it is named. The edition-level constraint above
         * is the one that has to hold, because that is the row a reader reaches.
         */
        DB::statement('ALTER TABLE cove_plans DROP CONSTRAINT IF EXISTS cove_plans_persona_is_undated_check');
        DB::statement(
            'ALTER TABLE cove_plans ADD CONSTRAINT cove_plans_dated_kind_check '.
            "CHECK (kind = 'daily' OR drop_date IS NULL)"
        );

        /*
         * A topic points at what it produced, and at the plan it seeded.
         *
         * `plan_id` is the new one: the topic queue stops being a second
         * publishing pipeline and becomes an idea feed, whose output is a draft
         * plan for a person to curate. `guide_id` stays until nothing reads it.
         */
        Schema::table('guide_topics', function (Blueprint $table) {
            $table->foreignId('edition_id')->nullable()->after('guide_id')
                ->constrained('daily_pick_sets')->nullOnDelete();
            $table->foreignId('plan_id')->nullable()->after('edition_id')
                ->constrained('cove_plans')->nullOnDelete();
        });

        /*
         * The data move lives in a service because it is the only part of this
         * that can lose something, and a migration body cannot be tested: by the
         * time a test has guides to fold, the migration has already run.
         */
        app(GuideFold::class)->run();
    }

    public function down(): void
    {
        $folded = DB::table('daily_pick_sets')
            ->whereIn('kind', [CoveKind::Guide->value, CoveKind::Seasonal->value, CoveKind::Advice->value])
            ->count();

        if ($folded > 0) {
            /*
             * Refuse rather than corrupt.
             *
             * Narrowing the kind CHECK back would fail against these rows
             * anyway, and the tempting fix — deleting them — throws away every
             * guide on the site. `guides` still holds the originals at this
             * point, so the recovery is to remove the folded editions
             * deliberately, having decided that is what you want.
             */
            throw new RuntimeException(
                "Cannot roll back: {$folded} folded guide edition(s) exist. Remove them deliberately first."
            );
        }

        Schema::table('guide_topics', function (Blueprint $table) {
            $table->dropConstrainedForeignId('plan_id');
            $table->dropConstrainedForeignId('edition_id');
        });

        DB::statement('ALTER TABLE cove_plans DROP CONSTRAINT IF EXISTS cove_plans_dated_kind_check');
        DB::statement(
            'ALTER TABLE cove_plans ADD CONSTRAINT cove_plans_persona_is_undated_check '.
            "CHECK (kind <> 'persona' OR drop_date IS NULL)"
        );

        DB::statement('ALTER TABLE daily_pick_sets DROP CONSTRAINT IF EXISTS daily_pick_sets_address_check');
        DB::statement(
            'ALTER TABLE daily_pick_sets ADD CONSTRAINT daily_pick_sets_address_check CHECK ('.
            "(kind = 'daily' AND drop_date IS NOT NULL AND slug IS NULL)".
            " OR (kind = 'persona' AND drop_date IS NULL AND slug IS NOT NULL))"
        );

        foreach (['daily_pick_sets', 'cove_plans'] as $table) {
            DB::statement("ALTER TABLE {$table} DROP CONSTRAINT IF EXISTS {$table}_kind_check");
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$table}_kind_check CHECK (kind IN ('daily', 'persona'))");
        }

        Schema::table('cove_plans', function (Blueprint $table) {
            $table->dropColumn(['body', 'faq', 'meta_description', 'focus_keyphrase', 'season_from', 'season_to']);
        });

        Schema::table('daily_picks', function (Blueprint $table) {
            $table->dropColumn(['verdict', 'unavailable']);
        });

        Schema::table('daily_pick_sets', function (Blueprint $table) {
            $table->dropConstrainedForeignId('featured_cove_id');
            // The unique index goes with the column in Postgres.
            $table->dropColumn([
                'body', 'faq', 'meta_description', 'focus_keyphrase',
                'source_queries', 'source_volume', 'last_checked_at',
                'season_from', 'season_to', 'folded_from_guide_id',
            ]);
        });
    }
};
