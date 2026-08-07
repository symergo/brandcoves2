<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Editable overrides for the declared discovery modes.
 *
 * The profiles themselves live in `config/discovery.php`, reviewed like code,
 * so the site works on a fresh database with this table empty. Rows here
 * override individual fields without a redeploy — tuning α from 0.9 to 0.8
 * after looking at a week of reaction data should not need a deployment, and
 * the alternative is nobody ever tuning it.
 *
 * Every column is nullable for the same reason: an override that only changes
 * λ should say only λ, not restate the whole profile and silently freeze the
 * rest at whatever the config said the day it was written.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mode_profiles', function (Blueprint $table) {
            $table->id();
            // Matches a key in config('discovery.modes'). A row whose key is not
            // declared is ignored rather than creating a mode with no layout.
            $table->string('key')->unique();

            $table->float('position')->nullable();
            // { retrieverKey: weight }
            $table->jsonb('retrievers')->nullable();
            // { alpha, beta, gamma, lambda, epsilon }
            $table->jsonb('scoring')->nullable();
            $table->string('layout')->nullable();
            $table->boolean('enabled')->nullable();

            // Why someone changed it. Ranking weights get tuned by whoever is
            // looking at the data that week, and six months later nobody
            // remembers why epsilon is 0.15.
            $table->text('note')->nullable();
            $table->timestamps();
        });

        /**
         * Per-mode reaction log.
         *
         * `{user, mode, input, item, reaction}` — the training data for tuning
         * ranking weights per mode. Separate from `events` because this one has
         * a fixed shape and will be aggregated constantly, while events is a
         * loosely-typed firehose nothing reads yet.
         */
        Schema::create('discovery_reactions', function (Blueprint $table) {
            $table->id();
            $table->string('mode');
            $table->string('market');
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
            $table->uuid('anon_id')->nullable();
            $table->foreignId('group_id')->nullable()->constrained('product_groups')->nullOnDelete();

            // save / click / meh / hide — deliberately not an enum column, see
            // the CHECK-over-enum note in the catalogue migration.
            $table->string('reaction');
            // The scoring factor that put this item on the page. Without it a
            // reaction says "they liked it" but not "they liked it *for the
            // reason we thought*", which is the half that tunes weights.
            $table->string('dominant_factor')->nullable();
            $table->smallInteger('position')->nullable();
            $table->jsonb('input')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['mode', 'created_at']);
            $table->index(['group_id', 'reaction']);
        });

        DB::statement(
            'ALTER TABLE discovery_reactions ADD CONSTRAINT discovery_reactions_reaction_check '.
            "CHECK (reaction IN ('save', 'click', 'meh', 'hide', 'mindblown'))"
        );

        DB::statement(
            'ALTER TABLE discovery_reactions ADD CONSTRAINT discovery_reactions_one_actor '.
            'CHECK (num_nonnulls(user_id, anon_id) >= 1)'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('discovery_reactions');
        Schema::dropIfExists('mode_profiles');
    }
};
