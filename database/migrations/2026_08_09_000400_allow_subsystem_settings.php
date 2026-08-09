<?php

declare(strict_types=1);

use App\Enums\Source;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Let `connector_settings` hold settings for subsystems, not only connectors.
 *
 * The table was built for connector credentials and its `source` column is
 * CHECK-constrained to the values of `App\Enums\Source`. AI settings need the
 * same thing — a handful of encrypted key/value pairs an administrator can
 * change without a deploy — and building a second table with an identical shape
 * to hold them would be worse than widening this one.
 *
 * So `source` becomes "which subsystem", of which a connector is one kind. The
 * constraint is still a constraint: `ai` is added explicitly rather than the
 * check being dropped, because the value is what routes a row to a settings
 * store and a typo'd source is a row nothing will ever read.
 *
 * Recreated rather than altered — Postgres has no ALTER CONSTRAINT for a CHECK,
 * and dropping plus adding inside one migration is transactional anyway. This is
 * also why these are string columns with checks rather than native PG enums:
 * altering one of those cannot run inside a transaction at all.
 */
return new class extends Migration
{
    /** Subsystems that are not connectors. */
    private const EXTRA_SOURCES = ['ai'];

    public function up(): void
    {
        DB::statement('ALTER TABLE connector_settings DROP CONSTRAINT IF EXISTS connector_settings_source_check');
        DB::statement("ALTER TABLE connector_settings ADD CONSTRAINT connector_settings_source_check CHECK (source IN ({$this->sources(withExtra: true)}))");
    }

    public function down(): void
    {
        // Rows for the new sources would violate the narrower constraint, so
        // they go first. They are settings, not data — recreated by an
        // administrator, or absent and the env values stand.
        DB::table('connector_settings')->whereIn('source', self::EXTRA_SOURCES)->delete();

        DB::statement('ALTER TABLE connector_settings DROP CONSTRAINT IF EXISTS connector_settings_source_check');
        DB::statement("ALTER TABLE connector_settings ADD CONSTRAINT connector_settings_source_check CHECK (source IN ({$this->sources(withExtra: false)}))");
    }

    private function sources(bool $withExtra): string
    {
        $values = Source::values();

        if ($withExtra) {
            $values = [...$values, ...self::EXTRA_SOURCES];
        }

        return implode(',', array_map(fn (string $s) => "'{$s}'", $values));
    }
};
