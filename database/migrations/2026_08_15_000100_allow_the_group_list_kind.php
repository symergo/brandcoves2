<?php

declare(strict_types=1);

use App\Enums\ListKind;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Let `wishlists.kind` be `group`.
 *
 * A third kind for several people choosing one gift for a third person, with
 * voting and pooled contributions. See docs/features/list-taxonomy.md for why
 * it is chosen at creation rather than derived from "a for_someone list that
 * has co-givers".
 *
 * This is exactly the deploy hazard the enum-ish convention exists to avoid.
 * A native Postgres enum cannot be altered inside a transaction, so adding a
 * value would make every future one a migration that cannot be rolled back
 * cleanly. A string plus a CHECK constraint is dropped and re-added in one
 * statement, inside the transaction, with no table rewrite — the constraint is
 * revalidated against existing rows, and every existing row already holds
 * `mine` or `for_someone`, both of which remain legal.
 *
 * Widening only. No row changes kind, so `down()` is safe as long as no group
 * list has been created yet; it deliberately refuses rather than silently
 * rewriting real lists into a kind that means something else.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE wishlists DROP CONSTRAINT IF EXISTS wishlists_kind_check');

        DB::statement(
            'ALTER TABLE wishlists ADD CONSTRAINT wishlists_kind_check CHECK (kind IN ('
            .implode(', ', array_map(fn (string $v) => "'".$v."'", ListKind::values()))
            .'))'
        );
    }

    public function down(): void
    {
        /*
         * Refuse rather than corrupt.
         *
         * Narrowing the constraint while `group` rows exist would fail on the
         * revalidation anyway — but the tempting "fix" is to UPDATE them to
         * for_someone first, and that would silently strip the voting and the
         * contributions from a list somebody is mid-way through organising.
         * Better to stop and make it a decision.
         */
        $groups = DB::table('wishlists')->where('kind', ListKind::Group->value)->count();

        if ($groups > 0) {
            throw new RuntimeException(
                "Cannot roll back: {$groups} group list(s) exist. Migrate them deliberately first."
            );
        }

        DB::statement('ALTER TABLE wishlists DROP CONSTRAINT IF EXISTS wishlists_kind_check');

        DB::statement(
            'ALTER TABLE wishlists ADD CONSTRAINT wishlists_kind_check CHECK (kind IN ('
            .implode(', ', array_map(
                fn (ListKind $k) => "'".$k->value."'",
                [ListKind::Mine, ListKind::ForSomeone],
            ))
            .'))'
        );
    }
};
