<?php

declare(strict_types=1);

use App\Enums\Source;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Let every table that names a source accept `tradedoubler`.
 *
 * The second instance of the file {@see 2026_09_02_000100_ebay_is_a_source}
 * predicted would be needed, and a deliberate copy of it rather than an edit to
 * it: that migration has already run everywhere, so widening its constraint set
 * in place would change nothing on any database that has seen it and would
 * diverge a fresh clone from staging — the exact failure both files exist to
 * prevent.
 *
 * Adding a case to {@see Source} is not, on its own, enough to store one. Seven
 * tables carry a CHECK listing the sources by value, each built from
 * `Source::values()` at the moment its own migration ran. Rebuilding all seven
 * from `Source::values()` here keeps this idempotent and convergent.
 */
return new class extends Migration
{
    /**
     * Tables whose `source` column holds a {@see Source} value, and nothing more.
     *
     * `connector_settings` is deliberately absent: its column also carries the
     * non-connector subsystems (`ai`, `ops`), so it is widened separately below.
     */
    private const TABLES = [
        'merchants',
        'feeds',
        'products',
        'ingestion_jobs',
        'chart_categories',
        'popular_ranks',
    ];

    /** Subsystems that are not connectors but share `connector_settings.source`. */
    private const SUBSYSTEMS = ['ai', 'ops'];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            $this->recheck($table, Source::values());
        }

        $this->recheck('connector_settings', [...Source::values(), ...self::SUBSYSTEMS]);
    }

    public function down(): void
    {
        $previous = array_values(array_filter(
            Source::values(),
            fn (string $source): bool => $source !== Source::Tradedoubler->value,
        ));

        foreach (self::TABLES as $table) {
            /*
             * Rows for the removed source would violate the narrower
             * constraint, so they go first.
             *
             * Safe to delete outright because Tradedoubler is a live source:
             * its `products` rows are a cache of offers re-fetched per request,
             * and its `merchants` rows are recreated on sight by OfferUpserter.
             * Deleting those merchants is the one visible consequence — the
             * shop directory loses its Tradedoubler advertisers until the next
             * search repopulates them.
             */
            DB::table($table)->where('source', Source::Tradedoubler->value)->delete();

            $this->recheck($table, $previous);
        }

        DB::table('connector_settings')->where('source', Source::Tradedoubler->value)->delete();

        $this->recheck('connector_settings', [...$previous, ...self::SUBSYSTEMS]);
    }

    /** @param list<string> $values */
    private function recheck(string $table, array $values): void
    {
        $quoted = implode(',', array_map(fn (string $value): string => "'{$value}'", $values));

        DB::statement("ALTER TABLE {$table} DROP CONSTRAINT IF EXISTS {$table}_source_check");
        DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$table}_source_check CHECK (source IN ({$quoted}))");
    }
};
