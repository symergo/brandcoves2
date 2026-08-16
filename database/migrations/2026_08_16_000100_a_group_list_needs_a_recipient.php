<?php

declare(strict_types=1);

use App\Enums\ListKind;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A `group` list must name the person it is for.
 *
 * The database half of the guarantee `ListMaker` makes in PHP. A group list
 * carries two mechanisms no other kind has — pooled contributions, and later
 * voting — and both are meaningless without a third person for the gift to be
 * for. "Several of us are buying something for nobody" is not a state the
 * product has a screen for, and a row in it would render an organiser page with
 * an empty name on it.
 *
 * Stated here as well as in the service because the service is one of two ways
 * a list gets made today and there is no guarantee it stays two. A CHECK
 * constraint is the version a third caller cannot forget.
 *
 * No backfill: `2026_08_15_000100` widened `wishlists_kind_check` to allow
 * `group`, and nothing could write that value until now, so there are no rows
 * to repair. The constraint is revalidated against existing rows on the way in
 * and every one of them holds `mine` or `for_someone`.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE wishlists DROP CONSTRAINT IF EXISTS wishlists_group_has_recipient');

        DB::statement(
            "ALTER TABLE wishlists ADD CONSTRAINT wishlists_group_has_recipient CHECK (kind <> '"
            .ListKind::Group->value
            ."' OR recipient_id IS NOT NULL)"
        );
    }

    public function down(): void
    {
        // Dropping a constraint cannot fail against existing rows, so unlike the
        // kind-widening migration this one needs no refusal: rolling back
        // permits more states rather than fewer.
        DB::statement('ALTER TABLE wishlists DROP CONSTRAINT IF EXISTS wishlists_group_has_recipient');
    }
};
