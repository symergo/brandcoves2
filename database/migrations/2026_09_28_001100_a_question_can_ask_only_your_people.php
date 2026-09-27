<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Ask others gets two audiences: the community board, or only your people
 * (owner, 2026-09-27). See docs/features/ask-others.md, "Ask the community or
 * ask your people".
 *
 * ## `audience`, a string with a CHECK
 *
 * `public` (the board, as every question was until now) or `people` (the
 * asker's friends and whoever holds the link). A string rather than a native
 * enum, per the convention. Default `public`, so every existing row stays
 * exactly what it was, and so does any code that inserts without naming it.
 *
 * ## `share_token`, the link's secret
 *
 * A people question is opened by `/ask/p/{token}`, never by its id: ids are
 * sequential, and a guessable address would make "only your people" mean
 * "anybody who counts". The same 10-character code a shared list uses
 * (`App\Support\ShareCode`). Unique where set; a public question has none.
 *
 * A CHECK ties the two together: a people question without a code would be
 * unreachable by its own audience, and a public one with a code would be a
 * second address for a board page.
 *
 * ## Safe on production
 *
 * On Postgres 16 a column added with a constant default is a catalogue change,
 * not a table rewrite. The CHECKs are added `NOT VALID` and then validated,
 * which reads the table without blocking writes; the table is small anyway.
 * Every step is guarded (`IF NOT EXISTS`, a lookup in `pg_constraint`),
 * because a failing migration is an outage here: Coolify stops the old
 * containers before `migrate` runs.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE community_questions ADD COLUMN IF NOT EXISTS audience varchar(16) NOT NULL DEFAULT 'public'");
        DB::statement('ALTER TABLE community_questions ADD COLUMN IF NOT EXISTS share_token varchar(32) NULL');

        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS community_questions_share_token_unique ON community_questions (share_token) WHERE share_token IS NOT NULL');

        $this->check(
            'community_questions_audience_check',
            "audience IN ('public', 'people')",
        );
        $this->check(
            'community_questions_people_have_a_link',
            "(audience = 'people') = (share_token IS NOT NULL)",
        );
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE community_questions DROP CONSTRAINT IF EXISTS community_questions_people_have_a_link');
        DB::statement('ALTER TABLE community_questions DROP CONSTRAINT IF EXISTS community_questions_audience_check');
        DB::statement('DROP INDEX IF EXISTS community_questions_share_token_unique');
        DB::statement('ALTER TABLE community_questions DROP COLUMN IF EXISTS share_token');
        DB::statement('ALTER TABLE community_questions DROP COLUMN IF EXISTS audience');
    }

    private function check(string $name, string $expression): void
    {
        $exists = DB::selectOne('SELECT 1 FROM pg_constraint WHERE conname = ?', [$name]) !== null;

        if (! $exists) {
            DB::statement("ALTER TABLE community_questions ADD CONSTRAINT {$name} CHECK ({$expression}) NOT VALID");
        }

        DB::statement("ALTER TABLE community_questions VALIDATE CONSTRAINT {$name}");
    }
};
