<?php

declare(strict_types=1);

use App\Enums\Market;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Gates the Filament admin panel. Set manually — there is no
            // self-service path to it.
            $table->boolean('is_admin')->default(false);
            $table->string('preferred_market')->nullable();
            // Opt-in for the Daily Picks digest and price-drop emails.
            $table->boolean('email_opt_in')->default(false);
            $table->string('avatar_url', 1024)->nullable();
        });

        DB::statement('ALTER TABLE users ADD CONSTRAINT users_preferred_market_check CHECK (preferred_market IS NULL OR preferred_market IN ('.$this->quoted(Market::values()).'))');

        // Case-insensitive uniqueness. Laravel's default unique index treats
        // Alice@ and alice@ as different people, which lets one human create two
        // accounts and lose their wishlists between them.
        //
        // Laravel's unique() creates a CONSTRAINT, and Postgres will not let you
        // drop the index out from under one — the constraint has to go first.
        DB::statement('ALTER TABLE users DROP CONSTRAINT IF EXISTS users_email_unique');
        DB::statement('CREATE UNIQUE INDEX users_email_lower_unique ON users (lower(email))');

        /**
         * Anonymous visitors.
         *
         * A signed cookie carries one of these ids so a wishlist or a gift
         * shortlist can be built before signing up, then merged into the account
         * afterwards. The whole point is that the gift wizard is useful without
         * demanding a login first.
         */
        Schema::create('anonymous_identities', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('merged_into_user_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->timestampTz('merged_at')->nullable();
            $table->timestampTz('last_seen_at')->useCurrent();
            $table->timestamps();

            $table->index('merged_into_user_id');
        });
    }

    /** @param list<string> $values */
    private function quoted(array $values): string
    {
        return implode(', ', array_map(fn (string $v) => "'".$v."'", $values));
    }

    public function down(): void
    {
        Schema::dropIfExists('anonymous_identities');

        DB::statement('DROP INDEX IF EXISTS users_email_lower_unique');
        DB::statement('ALTER TABLE users DROP CONSTRAINT IF EXISTS users_preferred_market_check');

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['is_admin', 'preferred_market', 'email_opt_in', 'avatar_url']);
            $table->unique('email');
        });
    }
};
