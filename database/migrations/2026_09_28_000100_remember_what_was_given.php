<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Gift history per person, and a way to stop reminder emails
 * (docs/features/gift-history.md, docs/features/occasion-reminders.md).
 *
 * ## `recipient_gifts`
 *
 * What somebody noted they gave one of their saved people: typed by hand on the
 * person's page, or an item from a list about that person marked "I gave
 * this". Claims are NOT copied in here: a claim is read live from
 * `wishlist_items` by the claimer's own hash (GiftHistory), so this table
 * never holds a second copy of claim state, and nothing in it can tell anybody
 * what somebody else bought (invariant 4).
 *
 * - `recipient_id` cascades: a person deleted takes their history with them.
 * - `group_id` is the product, when there is one. `nullOnDelete`, so a product
 *   a feed dropped leaves the line standing under its `title`. GroupMerger
 *   moves it on a merge.
 * - `wishlist_item_id` is the list item it came from, when it came from one.
 *   Unique per person, so pressing "I gave this" twice is one line.
 * - `given_year`: a year, not a date. "Last Christmas" and "her 60th" are what
 *   people remember; a day would be a question nobody can answer and a detail
 *   nothing reads. The CHECK keeps out typing mistakes, not history.
 *
 * ## `users.reminder_emails_off_at`
 *
 * Null means reminder emails are on, which is how they have always been sent.
 * A time means the person turned them off (the link in the email, or the
 * switch on the notifications page), and when. The in-app notification is
 * still written either way.
 *
 * ## Safe on production
 *
 * A new table, and one nullable column with no default on `users`, which
 * Postgres records in the catalogue without rewriting the table. Every
 * statement is written to run twice without failing, because a failing
 * migration is an outage here.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE TABLE IF NOT EXISTS recipient_gifts (
                id bigserial PRIMARY KEY,
                recipient_id uuid NOT NULL REFERENCES recipients(id) ON DELETE CASCADE,
                group_id bigint NULL REFERENCES product_groups(id) ON DELETE SET NULL,
                wishlist_item_id bigint NULL REFERENCES wishlist_items(id) ON DELETE SET NULL,
                title varchar(200) NOT NULL,
                given_year smallint NOT NULL,
                created_at timestamp(0) without time zone NULL,
                updated_at timestamp(0) without time zone NULL,
                CONSTRAINT recipient_gifts_title_present CHECK (btrim(title) <> ''),
                CONSTRAINT recipient_gifts_year_sane CHECK (given_year BETWEEN 1900 AND 2200)
            )
            SQL);

        DB::statement('CREATE INDEX IF NOT EXISTS recipient_gifts_recipient_idx ON recipient_gifts (recipient_id, given_year DESC)');
        DB::statement('CREATE INDEX IF NOT EXISTS recipient_gifts_group_idx ON recipient_gifts (group_id) WHERE group_id IS NOT NULL');
        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS recipient_gifts_item_unique ON recipient_gifts (recipient_id, wishlist_item_id) WHERE wishlist_item_id IS NOT NULL');

        DB::statement('ALTER TABLE users ADD COLUMN IF NOT EXISTS reminder_emails_off_at timestamp(0) with time zone NULL');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE users DROP COLUMN IF EXISTS reminder_emails_off_at');
        DB::statement('DROP TABLE IF EXISTS recipient_gifts');
    }
};
