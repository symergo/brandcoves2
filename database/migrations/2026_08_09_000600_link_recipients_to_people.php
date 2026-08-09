<?php

declare(strict_types=1);

use App\Enums\RecipientStatus;
use App\Enums\TasteSource;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A recipient stops being a note the giver keeps and becomes a person who can
 * speak for themselves.
 *
 * `share_token` has been minted on every row since the table was created, with
 * a comment saying it "lets the recipient fill in their own tastes" — and no
 * route ever resolved it. These two columns are what that token was waiting
 * for.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('recipients', function (Blueprint $table) {
            // Null until somebody claims the link. `nullOnDelete` because
            // deleting an account must not destroy the gift research done by
            // other people about that person.
            $table->foreignId('user_id')->nullable()->after('owner_anon_id')
                ->constrained('users')->nullOnDelete();

            $table->string('status')->default(RecipientStatus::Stub->value)->after('user_id');

            // Taste is answered as a block by one person; per-field provenance
            // would be finer than the thing it describes.
            $table->string('taste_source')->default(TasteSource::Suggested->value)->after('avoid');
        });

        DB::statement(
            'ALTER TABLE recipients ADD CONSTRAINT recipients_status_check CHECK (status IN ('
            .implode(', ', array_map(fn (string $v) => "'".$v."'", RecipientStatus::values()))
            .'))'
        );

        DB::statement(
            'ALTER TABLE recipients ADD CONSTRAINT recipients_taste_source_check CHECK (taste_source IN ('
            .implode(', ', array_map(fn (string $v) => "'".$v."'", TasteSource::values()))
            .'))'
        );

        /*
         * One account may be the subject of many people's recipient rows — your
         * mother is a recipient for you and for each of your siblings — but not
         * twice for the same owner, or "their list" appears twice on one page.
         */
        DB::statement('CREATE UNIQUE INDEX recipients_owner_user_idx ON recipients (owner_user_id, user_id) WHERE user_id IS NOT NULL AND owner_user_id IS NOT NULL');
        DB::statement('CREATE UNIQUE INDEX recipients_owner_anon_user_idx ON recipients (owner_anon_id, user_id) WHERE user_id IS NOT NULL AND owner_anon_id IS NOT NULL');

        Schema::table('recipients', function (Blueprint $table) {
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS recipients_owner_user_idx');
        DB::statement('DROP INDEX IF EXISTS recipients_owner_anon_user_idx');
        DB::statement('ALTER TABLE recipients DROP CONSTRAINT IF EXISTS recipients_status_check');
        DB::statement('ALTER TABLE recipients DROP CONSTRAINT IF EXISTS recipients_taste_source_check');

        Schema::table('recipients', function (Blueprint $table) {
            $table->dropIndex(['user_id']);
            $table->dropConstrainedForeignId('user_id');
            $table->dropColumn(['status', 'taste_source']);
        });
    }
};
