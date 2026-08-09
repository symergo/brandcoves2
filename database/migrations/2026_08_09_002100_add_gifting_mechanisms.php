<?php

declare(strict_types=1);

use App\Enums\EventType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Four gifting mechanisms the one-list-many-lenses model was missing.
 *
 * All of them are fields and edges on the tables that already exist rather than
 * new subsystems, which is the point of the lens model: a registry is a list
 * with an event, a handover is a change of owner, a suggestion is an item not
 * on the list yet, and a group gift is money pledged against one item.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wishlists', function (Blueprint $table) {
            /*
             * The standard "My wishlist".
             *
             * Saves have always landed in a lazily-created list called "Saved
             * items", which is a filing cabinet rather than a place. One list
             * per owner is *the* list: where a one-tap save goes, and what
             * somebody means when they say "my wishlist".
             */
            $table->boolean('is_default')->default(false);

            // Registry. Null on an ordinary list, and that is the difference.
            $table->string('event_type')->nullable();
            $table->date('event_date')->nullable();

            /*
             * Where to send it.
             *
             * Encrypted at rest, because a home address is the most sensitive
             * thing this application will ever hold and, unlike an email, it
             * cannot be rotated. Revealed only to somebody who has claimed an
             * item: publishing an address to everyone holding a registry link
             * is a different act from giving it to the person posting a parcel.
             */
            $table->text('delivery_address')->nullable();

            // A list handed to the person it was about stops being research and
            // becomes theirs. Recorded rather than dropped, so its history is
            // not a mystery to the new owner.
            $table->timestampTz('handed_over_at')->nullable();
        });

        DB::statement(
            'ALTER TABLE wishlists ADD CONSTRAINT wishlists_event_type_check CHECK (event_type IS NULL OR event_type IN ('
            .implode(', ', array_map(fn (string $v) => "'".$v."'", EventType::values()))
            .'))'
        );

        // Exactly one default per owner. Two would make "where did my save go?"
        // unanswerable, which is the question this feature exists to settle.
        DB::statement('CREATE UNIQUE INDEX wishlists_default_user_idx ON wishlists (owner_user_id) WHERE is_default AND owner_user_id IS NOT NULL');
        DB::statement('CREATE UNIQUE INDEX wishlists_default_anon_idx ON wishlists (owner_anon_id) WHERE is_default AND owner_anon_id IS NOT NULL');

        Schema::table('wishlist_items', function (Blueprint $table) {
            /*
             * "I think you would like this."
             *
             * A suggestion joins the list only once the owner accepts it, so a
             * pending one has a null `accepted_at` and every read of the list
             * proper filters on it. This attacks the empty-list problem from the
             * opposite side to the quiz: rather than you filling it in, the
             * people who know you do.
             */
            $table->foreignId('suggested_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('accepted_at')->nullable();
        });

        // Everything that exists today was put there by the owner, and is
        // therefore already accepted.
        DB::statement('UPDATE wishlist_items SET accepted_at = created_at');

        Schema::table('wishlist_items', function (Blueprint $table) {
            $table->index(['wishlist_id', 'accepted_at']);
        });

        /*
         * Group gift.
         *
         * Pledges, deliberately not payments. Four colleagues on one expensive
         * thing is a coordination problem — who is in, and for how much — and
         * the coordination is the hard part. Moving the money is a regulated
         * business, and people settle up between themselves anyway.
         *
         * The buyer is the existing claim on the item: one person claims it,
         * the others pledge against it, and the claimer collects in real life.
         */
        Schema::create('gift_pledges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('item_id')->constrained('wishlist_items')->cascadeOnDelete();

            $table->foreignId('user_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->uuid('anon_id')->nullable();

            // The name the other givers see. A pledge is a promise made to
            // people, and an anonymous column of amounts coordinates nobody.
            $table->string('display_name');

            // Cents, per invariant #7.
            $table->integer('amount');
            $table->timestamps();

            $table->foreign('anon_id')->references('id')->on('anonymous_identities')->cascadeOnDelete();
            $table->index('item_id');
        });

        DB::statement('ALTER TABLE gift_pledges ADD CONSTRAINT gift_pledges_one_pledger CHECK (num_nonnulls(user_id, anon_id) = 1)');
        DB::statement('ALTER TABLE gift_pledges ADD CONSTRAINT gift_pledges_positive CHECK (amount > 0)');

        // One pledge per person per item; changing your mind edits it.
        DB::statement('CREATE UNIQUE INDEX gift_pledges_user_idx ON gift_pledges (item_id, user_id) WHERE user_id IS NOT NULL');
        DB::statement('CREATE UNIQUE INDEX gift_pledges_anon_idx ON gift_pledges (item_id, anon_id) WHERE anon_id IS NOT NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('gift_pledges');

        DB::statement('DROP INDEX IF EXISTS wishlists_default_user_idx');
        DB::statement('DROP INDEX IF EXISTS wishlists_default_anon_idx');
        DB::statement('ALTER TABLE wishlists DROP CONSTRAINT IF EXISTS wishlists_event_type_check');

        Schema::table('wishlist_items', function (Blueprint $table) {
            $table->dropIndex(['wishlist_id', 'accepted_at']);
            $table->dropConstrainedForeignId('suggested_by_user_id');
            $table->dropColumn('accepted_at');
        });

        Schema::table('wishlists', function (Blueprint $table) {
            $table->dropColumn(['is_default', 'event_type', 'event_date', 'delivery_address', 'handed_over_at']);
        });
    }
};
