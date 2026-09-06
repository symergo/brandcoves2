<?php

declare(strict_types=1);

use App\Enums\AlertState;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A watched search: "tell me when something new matches this, under this price".
 *
 * The same machinery as a price alert, pointed at a query rather than a
 * product. `seen_group_ids` is what makes "new" mean new: the ids the search
 * matched when the watch was set, and every id it has matched since, so a
 * product is announced once and a run that finds the same thirty results says
 * nothing.
 *
 * One watch per person per term per market. The term is normalised before it
 * is stored (see SearchAlert::normalise), so "Lego" and "lego " are one watch.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('search_alerts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('market');
            $table->string('term', 120);
            // Cents, per invariant #7. Null means any price.
            $table->integer('max_price')->nullable();
            $table->string('state')->default(AlertState::Active->value);
            $table->jsonb('seen_group_ids')->default(DB::raw("'[]'::jsonb"));
            $table->timestampTz('last_checked_at')->nullable();
            $table->timestampTz('notified_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'market', 'term']);
            $table->index('state');
        });

        DB::statement("ALTER TABLE search_alerts ADD CONSTRAINT search_alerts_state_check CHECK (state IN ('active', 'triggered', 'cancelled'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('search_alerts');
    }
};
