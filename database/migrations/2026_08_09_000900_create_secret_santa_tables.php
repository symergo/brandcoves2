<?php

declare(strict_types=1);

use App\Enums\Market;
use App\Enums\SantaStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Secret Santa: an assignment layer, not a subsystem.
 *
 * Everything below the draw already exists. The giftee's list is an ordinary
 * wishlist; the reveal is a gated view of an ordinary share link; claiming
 * already stops two people in a family group buying the same thing; the claim
 * privacy rule already holds. Only the group, the membership and the pairing are
 * new.
 *
 * A group is deliberately **not** a `wishlist` row. It holds no items, and
 * overloading the table would mean `SharedListController::findShared()` could
 * serve a group as a list.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('secret_santa_groups', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('owner_user_id')->constrained('users')->cascadeOnDelete();
            $table->string('market');

            $table->string('title');
            $table->uuid('invite_token')->unique();

            // Cents, per invariant #7. v1 used DECIMAL(10,2) here and integer
            // cents everywhere else, which is exactly how a rounding difference
            // gets into a budget comparison.
            $table->integer('budget_min')->nullable();
            $table->integer('budget_max')->nullable();

            $table->date('exchange_date')->nullable();
            $table->string('theme')->nullable();

            $table->string('status')->default(SantaStatus::Open->value);
            $table->timestampTz('drawn_at')->nullable();
            $table->timestamps();

            $table->index(['owner_user_id', 'status']);
        });

        Schema::create('secret_santa_members', function (Blueprint $table) {
            $table->id();
            $table->uuid('group_id');

            /*
             * Nullable on purpose. Requiring an account to be in someone's
             * office Secret Santa is how most of the office does not join, and
             * the organiser then runs it in a spreadsheet instead. The join
             * token is what lets a member without an account read their own
             * assignment.
             */
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('email');
            $table->string('display_name');
            $table->uuid('join_token')->unique();
            $table->timestampTz('joined_at')->nullable();

            /*
             * Encrypted at rest.
             *
             * v1 stored the pairing in plain text and its own planning notes
             * flagged that as a defect: anyone with a database dump — a backup,
             * a support session, a scrubbed-but-not-quite laptop copy — could
             * read the whole game. Same class of rule as `claimed_by_hash`, and
             * the reason no `Recipient` row is minted at draw time: that would
             * put the giftee's name back in plain text one table over.
             */
            $table->text('assigned_member_id')->nullable();

            // Their own list, so the person who drew them has something to go on.
            $table->uuid('wishlist_id')->nullable();

            $table->timestampTz('marked_done_at')->nullable();
            $table->jsonb('exclusions')->default(DB::raw("'[]'::jsonb"));
            $table->timestamps();

            $table->foreign('group_id')->references('id')->on('secret_santa_groups')->cascadeOnDelete();
            $table->foreign('wishlist_id')->references('id')->on('wishlists')->nullOnDelete();

            // One membership per person per group: a double-submitted join form
            // would otherwise put someone in the hat twice.
            $table->unique(['group_id', 'email']);
            $table->index('user_id');
        });

        $markets = implode(', ', array_map(fn (string $m) => "'".$m."'", Market::values()));
        $statuses = implode(', ', array_map(fn (string $s) => "'".$s."'", SantaStatus::values()));

        DB::statement("ALTER TABLE secret_santa_groups ADD CONSTRAINT secret_santa_groups_market_check CHECK (market IN ($markets))");
        DB::statement("ALTER TABLE secret_santa_groups ADD CONSTRAINT secret_santa_groups_status_check CHECK (status IN ($statuses))");
    }

    public function down(): void
    {
        Schema::dropIfExists('secret_santa_members');
        Schema::dropIfExists('secret_santa_groups');
    }
};
