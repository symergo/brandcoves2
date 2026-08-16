<?php

declare(strict_types=1);

use App\Enums\Market;
use App\Enums\ModerationStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * "Ask others" — the first surface on this site that publishes what a visitor
 * wrote.
 *
 * Everything else a person types here is private by construction: a list is
 * theirs, a suggestion goes to one owner, a Secret Santa exclusion is read by a
 * draw algorithm and nobody. This is the first table whose rows are meant to be
 * read by strangers on an indexable page, which is why moderation is a column
 * rather than a plan.
 *
 * ## Why status is a string with a CHECK and not a boolean
 *
 * `published_at IS NULL` cannot tell "nobody has looked at this yet" from
 * "somebody looked and said no", and those need different screens, different
 * counts and different things said to the author. Three named states, per the
 * enum-ish convention: altering a native PG enum cannot run inside a
 * transaction, which makes every future value a deploy hazard.
 *
 * ## Scoped to a market, like everything else
 *
 * A question about what to buy a Belgian teenager is answered with products
 * sold in Belgium at Belgian prices. An unscoped board would mix five
 * catalogues and answer half its questions with things the asker cannot buy.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('community_questions', function (Blueprint $table) {
            $table->id();
            $table->string('market');

            // An account, not an `Owner`. Posting in public needs a person
            // behind it: somewhere to send the reply, and something to lose.
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();

            $table->string('title', 160);
            $table->text('body')->nullable();

            // Cents, per invariant #7 — the same unit as every other price on
            // the site, so an answer can be filtered against it directly.
            $table->integer('budget_max')->nullable();

            $table->string('status')->default(ModerationStatus::Pending->value);
            $table->timestampTz('published_at')->nullable();

            // Why it was held or refused. Shown to admins, and to the author in
            // the general form the copy allows — never the raw model output.
            $table->string('moderation_note')->nullable();

            /*
             * Denormalised, and only ever counting *published* answers.
             *
             * The board's index is the hottest read here and "12 answers" on
             * every row is otherwise a COUNT per row. Maintained by
             * `CommunityAnswer`'s model events, which is the only place an
             * answer changes status.
             */
            $table->unsignedInteger('answers_count')->default(0);

            $table->timestamps();

            // The board: one market, published, newest first.
            $table->index(['market', 'status', 'published_at']);

            // The moderation queue, and "my questions".
            $table->index(['status', 'created_at']);
            $table->index(['user_id', 'created_at']);
        });

        Schema::create('community_answers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('question_id')->constrained('community_questions')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();

            $table->text('body');

            $table->string('status')->default(ModerationStatus::Pending->value);
            $table->timestampTz('published_at')->nullable();
            $table->string('moderation_note')->nullable();

            $table->timestamps();

            $table->index(['question_id', 'status']);
            $table->index(['status', 'created_at']);
        });

        /*
         * The products an answer points at.
         *
         * A row here rather than a URL in the body, which is the whole reason
         * answers are useful on a site like this and not a liability. A pick is
         * a `product_groups` id we already hold, so it renders as an ordinary
         * product card, its price is live and correct for the market, and every
         * outbound link goes through `/go/{offer}` where the scheme is checked
         * (invariant #5). A stranger cannot paste a link into it because there
         * is nowhere to paste one.
         */
        Schema::create('community_answer_picks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('answer_id')->constrained('community_answers')->cascadeOnDelete();
            $table->foreignId('group_id')->constrained('product_groups')->cascadeOnDelete();

            // The order the answerer put them in: their first suggestion is
            // their best one, and re-sorting by price would say otherwise.
            $table->unsignedSmallInteger('position')->default(0);

            $table->timestamps();

            // One product once per answer. Pressing "add" twice is a double-tap,
            // not two recommendations.
            $table->unique(['answer_id', 'group_id']);
        });

        $in = fn (array $values): string => implode(', ', array_map(
            fn (string $v) => "'".$v."'",
            $values,
        ));

        DB::statement(
            'ALTER TABLE community_questions ADD CONSTRAINT community_questions_market_check CHECK (market IN ('
            .$in(Market::values()).'))'
        );

        foreach (['community_questions', 'community_answers'] as $table) {
            DB::statement(
                "ALTER TABLE {$table} ADD CONSTRAINT {$table}_status_check CHECK (status IN ("
                .$in(ModerationStatus::values()).'))'
            );

            /*
             * Published means dated, and dated means published.
             *
             * The board orders on `published_at` and filters on `status`, so a
             * row where the two disagree is either invisible while claiming to
             * be live, or live with no place in the ordering. Cheaper to make
             * that unrepresentable than to remember it at four call sites.
             */
            DB::statement(
                "ALTER TABLE {$table} ADD CONSTRAINT {$table}_published_is_dated CHECK ("
                ."(status = '".ModerationStatus::Published->value."') = (published_at IS NOT NULL))"
            );
        }

        // A budget of zero is a mistake rather than an answer, and a negative
        // one is a bug upstream.
        DB::statement(
            'ALTER TABLE community_questions ADD CONSTRAINT community_questions_budget_positive CHECK (budget_max IS NULL OR budget_max > 0)'
        );
    }

    public function down(): void
    {
        // Children first: the foreign keys are cascading, but dropping in
        // dependency order keeps this readable rather than relying on it.
        Schema::dropIfExists('community_answer_picks');
        Schema::dropIfExists('community_answers');
        Schema::dropIfExists('community_questions');
    }
};
