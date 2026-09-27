<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Thumbs up and down on Find a gift's ideas (owner's request, 2026-09-27):
 * "use this info to teach the engine". See docs/features/find-a-gift.md,
 * "Thumbs up, thumbs down".
 *
 * Two tables, because two different things are learned.
 *
 * - `recipient_feedback`: what the owner said about an idea for one of their
 *   saved people. Personal data about somebody else, so it hangs off the
 *   person and goes with them (cascade), and with the account, which deletes
 *   its people. One row per person and product: changing your mind replaces
 *   the vote.
 * - `gift_votes`: every thumb, for the crowd. One row per voter and product
 *   (a one-way code of the visitor, never the visitor), so pressing twice or
 *   from ten tabs moves nothing. `relationship` is the kind of person it was
 *   for (the `RecipientType` vocabulary), or null. Only a product enough
 *   different voters agree on ever counts (giftcoves.gift.feedback.min_voters).
 *   Aggregated when read, not kept as counters: a counter and its rows drift,
 *   and pruning the rows after a year (bc:prune-personal-data) would leave a
 *   counter nobody can explain.
 *
 * Vote columns are string + CHECK, not a Postgres enum (CLAUDE.md).
 *
 * ## Safe on production
 *
 * Two new, empty tables and nothing else: no backfill, no lock on an existing
 * table beyond the foreign keys. Guarded with hasTable, because a failing
 * migration is an outage (Coolify stops the old containers before migrate).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('recipient_feedback')) {
            Schema::create('recipient_feedback', function (Blueprint $table): void {
                $table->id();
                $table->foreignUuid('recipient_id')->constrained('recipients')->cascadeOnDelete();
                $table->foreignId('group_id')->constrained('product_groups')->cascadeOnDelete();
                $table->string('vote', 8);
                $table->timestamps();

                $table->unique(['recipient_id', 'group_id']);
            });

            DB::statement("ALTER TABLE recipient_feedback ADD CONSTRAINT recipient_feedback_vote_check CHECK (vote IN ('up', 'down'))");
        }

        if (! Schema::hasTable('gift_votes')) {
            Schema::create('gift_votes', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('group_id')->constrained('product_groups')->cascadeOnDelete();
                // Owner::identityHash('gift-vote'): an HMAC, purpose-scoped so it
                // cannot be joined against a claim or a pick reaction.
                $table->string('voter_hash', 64);
                $table->string('relationship', 32)->nullable();
                $table->string('vote', 8);
                $table->timestamps();

                // Also the index the engine reads by (group_id first).
                $table->unique(['group_id', 'voter_hash']);
                // For the nightly prune, which goes by age.
                $table->index('updated_at');
            });

            DB::statement("ALTER TABLE gift_votes ADD CONSTRAINT gift_votes_vote_check CHECK (vote IN ('up', 'down'))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('gift_votes');
        Schema::dropIfExists('recipient_feedback');
    }
};
