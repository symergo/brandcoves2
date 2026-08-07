<?php

declare(strict_types=1);

use App\Enums\IdentityKind;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The resolved identity, stored on the offer.
 *
 * Identity resolution is PHP (GS1 check digits, placeholder rejection, title
 * normalisation), so it runs once at ingest. Storing the result lets the
 * grouping pass be pure set-based SQL — three statements over the whole
 * catalogue — instead of pulling 60k rows into PHP on every run.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('identity_key')->nullable();
            $table->string('identity_kind')->nullable();
        });

        DB::statement('ALTER TABLE products ADD CONSTRAINT products_identity_kind_check CHECK (identity_kind IS NULL OR identity_kind IN ('.implode(', ', array_map(fn (string $v) => "'".$v."'", IdentityKind::values())).'))');

        // The grouping join. Partial because rows we could not identify — no
        // EAN, no brand, or a title too short to be discriminating — are
        // deliberately left ungrouped and never participate.
        DB::statement('CREATE INDEX products_identity_idx ON products (market, identity_key) WHERE identity_key IS NOT NULL');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS products_identity_idx');
        DB::statement('ALTER TABLE products DROP CONSTRAINT IF EXISTS products_identity_kind_check');

        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['identity_key', 'identity_kind']);
        });
    }
};
