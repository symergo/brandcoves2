<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Ask others reaches your people, and remembers which list it was asked for
 * (owner's request, 2026-09-26). See docs/features/ask-others.md, "Sent to your
 * people".
 *
 * ## Three switches on `users`, each a nullable timestamp where null is "on"
 *
 * - `ask_people_off_at`: the asker's side, "Send my questions to my people".
 * - `people_questions_off_at`: the receiver's side, "Show me my people's
 *   questions". Off stops the inbox row and the email.
 * - `people_question_emails_off_at`: the receiver's email only. The signed
 *   unsubscribe link in the email sets this and nothing else, so the question
 *   still reaches the inbox, the same way the reminder emails' stop link works
 *   (`reminder_emails_off_at`).
 *
 * All three are on by default because null is on: every existing account gets
 * the owner's default without a backfill, and the column says *when* somebody
 * turned it off, which is worth more than a boolean if one is ever questioned.
 *
 * ## Two columns on `community_questions`
 *
 * - `wishlist_id`: the list the question was asked from, if it was. Read only
 *   for the asker (their answers get a "save to this list" button); nobody
 *   else ever sees it. `ON DELETE SET NULL`: deleting the list must not delete
 *   a public question somebody has answered.
 * - `people_notified_at`: when the question was sent to the asker's people.
 *   Set once, by an update that only succeeds while it is null, so a question
 *   published twice (refused, then published by hand) is still sent once.
 *
 * ## Safe on production
 *
 * Nullable columns with no default are catalogue-only changes: no table
 * rewrite. The foreign key is checked against a column that is null in every
 * row, which is instant. `IF NOT EXISTS` everywhere, because a failing
 * migration is an outage here (Coolify stops the old containers before
 * `migrate` runs).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE users ADD COLUMN IF NOT EXISTS ask_people_off_at timestamp(0) with time zone NULL');
        DB::statement('ALTER TABLE users ADD COLUMN IF NOT EXISTS people_questions_off_at timestamp(0) with time zone NULL');
        DB::statement('ALTER TABLE users ADD COLUMN IF NOT EXISTS people_question_emails_off_at timestamp(0) with time zone NULL');

        DB::statement('ALTER TABLE community_questions ADD COLUMN IF NOT EXISTS wishlist_id uuid NULL REFERENCES wishlists(id) ON DELETE SET NULL');
        DB::statement('ALTER TABLE community_questions ADD COLUMN IF NOT EXISTS people_notified_at timestamp(0) with time zone NULL');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE community_questions DROP COLUMN IF EXISTS people_notified_at');
        DB::statement('ALTER TABLE community_questions DROP COLUMN IF EXISTS wishlist_id');

        DB::statement('ALTER TABLE users DROP COLUMN IF EXISTS people_question_emails_off_at');
        DB::statement('ALTER TABLE users DROP COLUMN IF EXISTS people_questions_off_at');
        DB::statement('ALTER TABLE users DROP COLUMN IF EXISTS ask_people_off_at');
    }
};
