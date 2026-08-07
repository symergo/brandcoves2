<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * One alert per person per product.
     *
     * The controller uses updateOrCreate, which is a read-then-write and will
     * happily insert twice if the same person double-taps the button. Enforced
     * in the database instead: two rows would mean two notifications for the
     * same drop.
     *
     * Partial, on user_id IS NOT NULL, because the email-only alert path (an
     * address with no account) can legitimately have several rows for the same
     * product from different addresses.
     */
    public function up(): void
    {
        DB::statement(
            'CREATE UNIQUE INDEX price_alerts_group_user_idx ON price_alerts (group_id, user_id) WHERE user_id IS NOT NULL'
        );

        DB::statement(
            'CREATE UNIQUE INDEX restock_alerts_group_user_idx ON restock_alerts (group_id, user_id) WHERE user_id IS NOT NULL'
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS price_alerts_group_user_idx');
        DB::statement('DROP INDEX IF EXISTS restock_alerts_group_user_idx');
    }
};
