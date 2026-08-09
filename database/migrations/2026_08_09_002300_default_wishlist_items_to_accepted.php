<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * An item is on the list unless somebody says otherwise.
 *
 * `accepted_at` arrived nullable with no default, so a null meant "pending
 * suggestion" — and therefore *invisible*, because `Wishlist::items()` filters
 * on it. That made forgetting the column the dangerous case: any insert that
 * did not set it produced a row that existed in the table and appeared nowhere,
 * with no error to say so. Three tests that create items directly went blank
 * immediately, which is the cheap version of the same bug finding production.
 *
 * Defaulting to `now()` inverts it. The ordinary path is right by omission, and
 * the unusual state — a suggestion awaiting a decision — has to be asked for
 * explicitly, which is exactly what `SuggestionController` does.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE wishlist_items ALTER COLUMN accepted_at SET DEFAULT now()');

        // Anything already invisible was written before the default existed.
        // A pending suggestion is minutes old at most, and none can predate
        // this migration.
        DB::statement('UPDATE wishlist_items SET accepted_at = created_at WHERE accepted_at IS NULL AND suggested_by_user_id IS NULL');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE wishlist_items ALTER COLUMN accepted_at DROP DEFAULT');
    }
};
