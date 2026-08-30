<?php

declare(strict_types=1);

use App\Enums\Source;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Let every table that names a source accept `ebay`.
 *
 * Adding a case to {@see Source} is not, on its own, enough to store one. Seven
 * tables carry a CHECK listing the sources by value, each built from
 * `Source::values()` **at the moment its own migration ran** — so a fresh clone
 * silently gets the new source and every database that has already migrated
 * does not. That divergence is the whole reason this file exists: without it
 * eBay works perfectly on a laptop created today and every eBay offer on
 * staging fails its insert with a constraint violation, from code that is
 * identical on both.
 *
 * Rebuilt from `Source::values()` rather than from a literal list, so this is
 * idempotent and convergent: run against a database that already allows `ebay`
 * it produces the same constraint, and a *future* source needs a file like this
 * one rather than an edit to this one.
 *
 * Recreated rather than altered, and drop-plus-add inside one migration is
 * transactional — which is also why these are string columns with checks and
 * not native PG enums, since altering one of those cannot run in a transaction
 * at all.
 */
return new class extends Migration
{
    /**
     * Tables whose `source` column holds a {@see Source} value, and nothing more.
     *
     * `connector_settings` is deliberately absent: its column also carries the
     * non-connector subsystems (`ai`, `ops`), so it is widened separately below
     * rather than being narrowed back to the connector set by a shared loop.
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
            fn (string $source): bool => $source !== Source::Ebay->value,
        ));

        foreach (self::TABLES as $table) {
            /*
             * Rows for the removed source would violate the narrower
             * constraint, so they go first.
             *
             * Safe to delete outright because eBay is a live source: nothing
             * here is a durable record of anything. Its `products` rows are a
             * cache of listings that expire on their own, its `merchants` row
             * is recreated on sight by OfferUpserter, and it charts nothing.
             * The one thing a rollback does lose is `popular_ranks` history,
             * and eBay writes none — see EbayConnector's note on not being a
             * PopularityConnector.
             */
            DB::table($table)->where('source', Source::Ebay->value)->delete();

            $this->recheck($table, $previous);
        }

        DB::table('connector_settings')->where('source', Source::Ebay->value)->delete();

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
