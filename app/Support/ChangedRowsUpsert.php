<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Database\Query\Expression;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/**
 * `INSERT ... ON CONFLICT DO UPDATE`, but only where something changed.
 *
 * Laravel's `upsert()` rewrites every conflicting row, whether or not a value
 * moved. In Postgres an UPDATE is a new copy of the row plus a new entry in
 * every index on it, so rewriting an unchanged row costs as much as changing
 * it. The feeds are mostly the same from one day to the next: before this
 * (2026-09-28) the twice-daily ingest rewrote every offer twice a day, and each
 * rewrite recomputed the stored `search_vector` and added GIN and trigram index
 * entries for a row whose text had not changed.
 *
 * This compiles the same statement `upsert()` would and adds
 *
 *     WHERE (table.a, table.b, ...) IS DISTINCT FROM (excluded.a, excluded.b, ...)
 *
 * so a row that arrives identical is left alone. `IS DISTINCT FROM` and not
 * `<>`, because `<>` says NULL (not true) when either side is NULL, and a
 * description going from NULL to text is a change.
 *
 * The return value is the number of rows inserted or actually updated.
 */
final class ChangedRowsUpsert
{
    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  list<string>  $uniqueBy
     * @param  array<int|string, string|Expression>  $update  as for upsert()
     * @param  list<string>  $compare  the columns whose change is worth a write
     * @param  string|null  $orWhen  a further SQL condition that also counts as a change
     */
    public static function run(
        string $table,
        array $rows,
        array $uniqueBy,
        array $update,
        array $compare,
        ?string $orWhen = null,
    ): int {
        if ($rows === []) {
            return 0;
        }

        // What upsert() does: the grammar reads the columns off the first row,
        // so every row must list them in the same order.
        foreach ($rows as $i => $row) {
            ksort($row);
            $rows[$i] = $row;
        }

        $query = DB::table($table);
        $grammar = $query->getGrammar();

        $sql = $grammar->compileUpsert($query, $rows, $uniqueBy, $update);

        $mine = implode(', ', array_map(fn (string $c) => $grammar->wrap($table.'.'.$c), $compare));
        $theirs = implode(', ', array_map(fn (string $c) => 'excluded.'.$grammar->wrap($c), $compare));

        $sql .= " where ({$mine}) is distinct from ({$theirs})";

        if ($orWhen !== null) {
            $sql .= " or ({$orWhen})";
        }

        $bindings = $query->cleanBindings(array_merge(
            Arr::flatten($rows, 1),
            array_filter($update, fn ($value, $key) => ! is_int($key), ARRAY_FILTER_USE_BOTH),
        ));

        return DB::affectingStatement($sql, $bindings);
    }
}
