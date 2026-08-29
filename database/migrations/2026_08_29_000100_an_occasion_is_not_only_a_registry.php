<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Widen `wishlists_event_type_check` past the five occasions a registry has.
 *
 * The occasion was only ever editable on a `mine` list, so the five values were
 * the ones somebody sets up a registry for: wedding, baby, housewarming,
 * birthday, other. Now that any list of any kind may carry one, the ordinary
 * gifting calendar is missing — Christmas above all, which is the single
 * biggest occasion in the product and had to be filed as "Something else".
 *
 * ## The values are written out, and the original migration's are not
 *
 * `2026_08_09_002100_add_gifting_mechanisms` builds this constraint by calling
 * `EventType::values()`. That is why this migration is needed at all *and* why
 * it does not do the same thing: a migration that reads application code
 * describes a different schema every time the code changes, so replaying it on
 * a fresh database produces something that never existed on any real one. Here
 * the two disagree by ten values, which is exactly the drift.
 *
 * The literal list below is therefore a snapshot, deliberately frozen. The next
 * occasion added needs its own migration — and that is the point: adding an
 * enum case is a schema change, and it should cost one.
 *
 * Dropped and re-added rather than altered, because a CHECK constraint cannot
 * be widened in place. `IF EXISTS` because a database migrated from scratch
 * after `EventType` grew already has the full set from the original migration,
 * and this must be a no-op there rather than an error.
 */
return new class extends Migration
{
    /**
     * Every value `EventType` holds as of 2026-08-29.
     *
     * @var list<string>
     */
    private const VALUES = [
        'birthday',
        'christmas',
        'wedding',
        'anniversary',
        'baby',
        'housewarming',
        'graduation',
        'retirement',
        'farewell',
        'valentines',
        'mothers_day',
        'fathers_day',
        'thank_you',
        'other',
    ];

    public function up(): void
    {
        $values = implode(', ', array_map(
            fn (string $value) => "'".$value."'",
            self::VALUES,
        ));

        DB::statement('ALTER TABLE wishlists DROP CONSTRAINT IF EXISTS wishlists_event_type_check');

        DB::statement(
            'ALTER TABLE wishlists ADD CONSTRAINT wishlists_event_type_check '
            ."CHECK (event_type IS NULL OR event_type IN ({$values}))"
        );
    }

    /**
     * Deliberately not narrowed back.
     *
     * Migrations here are forward-only, and a `down()` that restored the five
     * would fail on any row that had since chosen one of the ten — turning a
     * rollback into an outage rather than a rollback. Dropping the constraint
     * is the honest inverse: it loosens, and loosening never rejects a row that
     * is already there.
     */
    public function down(): void
    {
        DB::statement('ALTER TABLE wishlists DROP CONSTRAINT IF EXISTS wishlists_event_type_check');
    }
};
