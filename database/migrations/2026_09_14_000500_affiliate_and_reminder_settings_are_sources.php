<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Two more subsystems allowed to keep settings in `connector_settings`.
 *
 * **`affiliate`** is new: the shop and affiliate settings page, see
 * `AffiliateSettingsStore`.
 *
 * **`reminders`** should have arrived with the reminder settings page and did
 * not. `ReminderSettingsStore` writes under that source, the check below never
 * listed it, and so saving that page on production fails with a check
 * violation. Found on 2026-09-14 while adding `affiliate` to the same list;
 * production has no `reminders` rows, which is what a page that has never
 * managed to save looks like.
 *
 * A string with a CHECK rather than a Postgres enum, per CLAUDE.md, so adding a
 * value is a replace. The new list is a superset of the old one and the table
 * holds a few dozen rows, so every existing row passes the new check: this
 * cannot fail on data that is already there, which matters because a failing
 * migration here is an outage.
 */
return new class extends Migration
{
    private const BEFORE = ['awin', 'bol', 'ebay', 'tradedoubler', 'amazon', 'manual', 'ai', 'ops', 'automation'];

    private const AFTER = ['awin', 'bol', 'ebay', 'tradedoubler', 'amazon', 'manual', 'ai', 'ops', 'automation', 'reminders', 'affiliate'];

    public function up(): void
    {
        $this->allow(self::AFTER);
    }

    public function down(): void
    {
        // The old check would refuse these rows, so they go first.
        DB::table('connector_settings')->whereIn('source', ['reminders', 'affiliate'])->delete();

        $this->allow(self::BEFORE);
    }

    /** @param list<string> $sources */
    private function allow(array $sources): void
    {
        $list = implode(', ', array_map(fn (string $s) => "'{$s}'", $sources));

        DB::statement('ALTER TABLE connector_settings DROP CONSTRAINT IF EXISTS connector_settings_source_check');
        DB::statement("ALTER TABLE connector_settings ADD CONSTRAINT connector_settings_source_check CHECK (source IN ({$list}))");
    }
};
