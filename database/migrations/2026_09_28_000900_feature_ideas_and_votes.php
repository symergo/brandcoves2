<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The contribute page's voting board (owner's request, 2026-09-27). See
 * docs/features/contribute.md.
 *
 * ## `feature_ideas` — one row per idea, every language on the row
 *
 * The site's other translated editorial text (page blocks, email templates)
 * is one row per language. That does not fit here: a vote is for the idea,
 * not for its Dutch wording, and one row per language would split the count
 * four ways or need a group key to add it back up. So `title` and `body` are
 * jsonb keyed by language (`{"nl": "…", "en": "…"}`), and `language` records
 * the one it was first written in, which is the fallback when the reader's
 * language has no text yet (a visitor's suggestion arrives in one language
 * only; the owner fills in the others in the admin).
 *
 * - `status`: where the idea stands. `considering`, `planned`, `building`,
 *   `done`.
 * - `moderation`: whether anybody but its author may see it (the
 *   ModerationStatus values: `pending`, `published`, `rejected`). A visitor's
 *   suggestion starts `pending` and reaches the page only when a person
 *   publishes it in the admin; nothing a stranger writes is shown by
 *   omission, and no AI looks at it.
 * - `source`: `seed` (shipped in resources/content/feature-ideas.php and still
 *   as shipped), `owner` (written or edited in the admin), `visitor`
 *   (suggested on the page). The seed command rewrites only `seed` rows, so an
 *   edit in the admin is never overwritten.
 * - `seed_key`: the idea's key in the content file, so a re-seed finds its row.
 * - `suggested_by`: null on delete. Deleting an account must not delete an
 *   idea other people voted for; the idea is anonymous once the account is
 *   gone. The author's name is never shown anyway.
 * - `decided_at`: when it was published or rejected. A rejected suggestion
 *   is deleted a year after (bc:prune-personal-data).
 *
 * Enum-ish columns are strings with a CHECK, never Postgres enums, as
 * everywhere here.
 *
 * ## `feature_votes` — one vote per person per idea
 *
 * Signed-in only, so a vote is a (idea, user) pair, unique. Cascades both
 * ways: deleting an idea deletes its votes, deleting an account deletes the
 * account's votes.
 *
 * ## Safe on production
 *
 * New tables only. Guarded with hasTable, because a failing migration is an
 * outage here (Coolify stops the old containers before `migrate` runs).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('feature_ideas')) {
            Schema::create('feature_ideas', function (Blueprint $table): void {
                $table->id();
                $table->string('seed_key', 80)->nullable()->unique();
                $table->jsonb('title');
                $table->jsonb('body')->default('{}');
                $table->string('language', 2);
                $table->string('status', 16)->default('considering');
                $table->string('moderation', 16)->default('pending');
                $table->string('source', 16);
                $table->foreignId('suggested_by')->nullable()->constrained('users')->nullOnDelete();
                // Where it was suggested, for the admin's context only.
                $table->string('market', 8)->nullable();
                $table->integer('sort')->default(0);
                $table->timestamp('decided_at')->nullable();
                $table->timestamps();

                // The public board reads published rows; the admin queue
                // reads pending ones.
                $table->index('moderation');
                // "How many did this person suggest today?" (the rate limit).
                $table->index(['suggested_by', 'created_at']);
            });

            DB::statement(
                "ALTER TABLE feature_ideas ADD CONSTRAINT feature_ideas_status_check
                 CHECK (status IN ('considering', 'planned', 'building', 'done'))"
            );
            DB::statement(
                "ALTER TABLE feature_ideas ADD CONSTRAINT feature_ideas_moderation_check
                 CHECK (moderation IN ('pending', 'published', 'rejected'))"
            );
            DB::statement(
                "ALTER TABLE feature_ideas ADD CONSTRAINT feature_ideas_source_check
                 CHECK (source IN ('seed', 'owner', 'visitor'))"
            );
            DB::statement(
                "ALTER TABLE feature_ideas ADD CONSTRAINT feature_ideas_language_check
                 CHECK (language IN ('nl', 'en', 'fr', 'es'))"
            );
        }

        if (! Schema::hasTable('feature_votes')) {
            Schema::create('feature_votes', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('feature_idea_id')->constrained('feature_ideas')->cascadeOnDelete();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->timestamp('created_at')->useCurrent();

                $table->unique(['feature_idea_id', 'user_id']);
                // "Which did I vote for?", on every render of the page.
                $table->index('user_id');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('feature_votes');
        Schema::dropIfExists('feature_ideas');
    }
};
