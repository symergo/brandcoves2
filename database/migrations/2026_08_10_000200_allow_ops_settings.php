<?php

declare(strict_types=1);

use App\Enums\Source;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Let `connector_settings` hold operational settings too.
 *
 * The migration screen stores one: a Coolify deploy webhook, which redeploys
 * this application and nothing else. It belongs in the same encrypted store as
 * the AI credential for the same reasons — an administrator sets it without a
 * deploy, and a production dump restored on a laptop must yield noise rather
 * than a URL that redeploys production.
 *
 * `ops` is added explicitly rather than the check being dropped. The value is
 * what routes a row to a settings store, so a typo'd source is a row nothing
 * will ever read, and a constraint that no longer constrains would not catch it.
 * This follows {@see 2026_08_09_000400_allow_subsystem_settings} exactly.
 *
 * Recreated rather than altered: Postgres has no ALTER CONSTRAINT for a CHECK,
 * and drop-plus-add inside one migration is transactional anyway. Which is also
 * why these are string columns with checks rather than native PG enums —
 * altering one of those cannot run inside a transaction at all.
 */
return new class extends Migration
{
    /** Subsystems that are not connectors, in the order they were added. */
    private const EXTRA_SOURCES = ['ai', 'ops'];

    private const PREVIOUS_SOURCES = ['ai'];

    public function up(): void
    {
        DB::statement('ALTER TABLE connector_settings DROP CONSTRAINT IF EXISTS connector_settings_source_check');
        DB::statement("ALTER TABLE connector_settings ADD CONSTRAINT connector_settings_source_check CHECK (source IN ({$this->sources(self::EXTRA_SOURCES)}))");
    }

    public function down(): void
    {
        // Rows for the removed source would violate the narrower constraint, so
        // they go first. It is a setting, not data — an administrator pastes the
        // webhook again, or the deploy button simply hides itself.
        DB::table('connector_settings')
            ->whereIn('source', array_diff(self::EXTRA_SOURCES, self::PREVIOUS_SOURCES))
            ->delete();

        DB::statement('ALTER TABLE connector_settings DROP CONSTRAINT IF EXISTS connector_settings_source_check');
        DB::statement("ALTER TABLE connector_settings ADD CONSTRAINT connector_settings_source_check CHECK (source IN ({$this->sources(self::PREVIOUS_SOURCES)}))");
    }

    /** @param list<string> $extra */
    private function sources(array $extra): string
    {
        $values = [...Source::values(), ...$extra];

        return implode(',', array_map(fn (string $s) => "'{$s}'", $values));
    }
};
