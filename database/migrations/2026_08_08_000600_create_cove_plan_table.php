<?php

declare(strict_types=1);

use App\Enums\Market;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The editorial calendar: what each day is *for*, decided in advance.
 *
 * Until now a Daily Cove was assembled at 06:00 and published at 09:00, and
 * nobody could see it before the readers did. That is fine while the theme is a
 * generated line and fatal the moment it is an occasion — you cannot plan
 * around Mother's Day three hours before it starts.
 *
 * A plan row is an *intention*, not an edition. The builder reads it and does
 * the work; the edition remains the thing that gets published. Keeping those
 * separate matters because a plan can exist for a date the catalogue later
 * cannot fill, and an empty edition is a worse outcome than a plan that did not
 * come off.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cove_plans', function (Blueprint $table) {
            $table->id();
            $table->string('market');

            /*
             * Null for a themed Cove, set for a Daily Cove.
             *
             * The two kinds share this table because they are the same
             * editorial decision — "what is this one about" — made at different
             * cadences. A dated plan drives one morning; an undated one waits
             * in the queue until someone builds it.
             */
            $table->date('drop_date')->nullable();

            $table->string('title');
            $table->text('blurb')->nullable();

            // Biases the finds. Same contract as an observance: a bias, never a
            // filter, so a thin catalogue day still publishes.
            $table->jsonb('queries')->default(DB::raw("'[]'::jsonb"));

            /*
             * Products an editor chose by hand.
             *
             * These lead the edition ahead of anything the engine picked. The
             * whole point of curation is to override a score, so a pin that the
             * ranker could veto would not be a pin.
             */
            $table->jsonb('pinned_group_ids')->default(DB::raw("'[]'::jsonb"));

            // draft → approved → used. Only `approved` is picked up by the
            // builder: a half-written plan must never reach a reader because
            // the clock came round.
            $table->string('status')->default('draft');

            $table->foreignId('edition_id')->nullable()
                ->constrained('daily_pick_sets')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('note')->nullable();
            $table->timestamps();

            $table->index(['market', 'status', 'drop_date']);
        });

        DB::statement(
            'ALTER TABLE cove_plans ADD CONSTRAINT cove_plans_market_check CHECK (market IN ('.
            implode(', ', array_map(fn (string $m) => "'".$m."'", Market::values())).'))'
        );

        DB::statement(
            'ALTER TABLE cove_plans ADD CONSTRAINT cove_plans_status_check '.
            "CHECK (status IN ('draft', 'approved', 'used', 'rejected'))"
        );

        /*
         * One dated plan per market per day.
         *
         * Two plans for one Tuesday is an editorial argument the builder cannot
         * settle, and it would settle it silently by ordering. Undated plans are
         * exempt — the queue can hold as many ideas as it likes.
         */
        DB::statement(
            'CREATE UNIQUE INDEX cove_plans_market_date_idx ON cove_plans (market, drop_date) '.
            'WHERE drop_date IS NOT NULL'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('cove_plans');
    }
};
