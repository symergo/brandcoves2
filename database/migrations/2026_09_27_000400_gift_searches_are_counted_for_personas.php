<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How often each gift brief was searched for, per day
 * (docs/features/persona-demand.md).
 *
 * A gift search ("cadeau voor mijn zus die van yoga houdt") is answered by
 * the suggestion engine and never reaches `search_log`, so the site's own
 * demand signal was blind to exactly the searches a gift persona answers.
 * This counts them, and keeps only the reading: who (`relationship`), what
 * they love (`interest`), and whether they "have everything". Not the words,
 * not the budget, not who searched. One row per market, day and reading, the
 * count going up; a search naming two interests counts once for each.
 *
 * "None" is an empty string rather than null, so the unique key needs no
 * NULLS NOT DISTINCT and the upsert's conflict target is plain columns.
 *
 * No CHECK on the vocabulary: written only from the enums by
 * App\Services\Gift\GiftSearchDemand, and a CHECK would make every new
 * interest a migration. A new table, so nothing here locks anything.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gift_search_demand', function (Blueprint $table) {
            $table->id();
            $table->string('market', 8);
            $table->date('day');
            // RecipientType value, or '' when the search named nobody.
            $table->string('relationship', 20)->default('');
            // Interest value, or '' when it named none (a has-everything search).
            $table->string('interest', 20)->default('');
            $table->boolean('has_everything')->default(false);
            $table->unsignedInteger('searches')->default(0);
            $table->timestamps();

            $table->unique(['market', 'day', 'relationship', 'interest', 'has_everything'], 'gift_search_demand_reading_unique');
            // The nightly read: one market, a window of days.
            $table->index(['market', 'day']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gift_search_demand');
    }
};
