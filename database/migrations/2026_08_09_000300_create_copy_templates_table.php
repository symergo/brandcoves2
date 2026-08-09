<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;

/**
 * Editable, rotating variants of the templated copy on search and brand pages.
 *
 * ## Why this leaves the language files
 *
 * The prose on those pages lived in the language files, which is right for chrome —
 * a button label changes when a developer changes it — and wrong for editorial.
 * Editorial wants two things a PHP file cannot give it: someone without a
 * deployment pipeline should be able to rewrite a sentence, and there should be
 * more than one sentence to choose from.
 *
 * The second is the point. These pages number in the thousands, and thousands of
 * pages opening with one identical sentence is a pattern visible in a single
 * sample. Several variants per slot, handed out by a stable rotation, makes the
 * corpus look written rather than stamped.
 *
 * ## What a row is
 *
 * One **variant** of one **slot** on one **surface**, in one language. A slot is
 * a position in the page's argument — "the second sentence about comparing" — and
 * the code asks for the slot, never for a specific row.
 *
 * `weight` biases the draw. A variant an editor likes at 3 appears three times as
 * often as one at 1; setting 0 is the soft way to retire a line without deleting
 * the evidence that it existed.
 *
 * ## Nothing here is required
 *
 * `CopyBank` falls back to the language file whenever a slot has no enabled
 * variant. That is deliberate and load-bearing: it means this table can be empty,
 * half-filled, or wrong, and the site still renders the copy it shipped with. An
 * editor cannot break a page by deleting a row.
 *
 * ## The placeholder problem
 *
 * A variant is a template with `:count` and `:brand` in it, and a typo'd `:cont`
 * renders literally to a reader. Worse, a placeholder in the wrong slot can make
 * a claim the page cannot back — `:percent` in a sentence that renders even when
 * nothing is discounted would assert a 0% saving. So each slot declares which
 * placeholders it may contain and the admin form rejects the rest. See
 * `App\Services\Seo\CopySlots`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('copy_templates', function (Blueprint $table) {
            $table->id();

            // Which page this belongs to. See CopySlots::surfaces().
            $table->string('surface');
            // The position within that page's argument, e.g. `compare_1`.
            $table->string('slot');
            /*
             * Language, not market. be-nl and nl-nl share every word on these
             * pages and differ only in catalogue and currency — the same reason
             * lang/ is keyed by language.
             */
            $table->string('language', 5);

            $table->text('body');

            /*
             * Relative frequency, not a percentage.
             *
             * Percentages have to sum to 100, which means editing one variant
             * forces edits to its siblings. A weight is local: raise this line
             * and it appears more often, without touching anything else.
             */
            $table->smallInteger('weight')->default(1);
            $table->boolean('enabled')->default(true);

            // Why this variant exists, for whoever inherits it.
            $table->text('note')->nullable();

            $table->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // The lookup CopyBank does on every render: one query per language.
            $table->index(['language', 'enabled']);
            $table->index(['surface', 'slot', 'language']);
        });

        DB::statement("ALTER TABLE copy_templates ADD CONSTRAINT copy_templates_surface_check CHECK (surface IN ('search', 'brand', 'brand_intro'))");
        // A negative weight would silently invert the draw.
        DB::statement('ALTER TABLE copy_templates ADD CONSTRAINT copy_templates_weight_check CHECK (weight >= 0 AND weight <= 100)');
    }

    public function down(): void
    {
        Schema::dropIfExists('copy_templates');
    }
};
