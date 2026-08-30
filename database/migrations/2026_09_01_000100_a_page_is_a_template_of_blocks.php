<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;

/**
 * A page is a template: named regions holding an ordered list of editor-written blocks.
 *
 * ## What this replaces, and why replacing it rather than extending it
 *
 * `copy_templates` held rotating alternatives for ~35 positions declared in
 * `App\Services\Seo\CopySlots`. An editor could rewrite the words in a position
 * and add alternatives to it — and the positions themselves were a line of code
 * each. Their order, their guards, the fact that there were exactly three
 * sections below a results grid and no place for prose anywhere else on the
 * page: all of it a deploy. "We should also explain returns here" was a
 * developer's afternoon.
 *
 * So the position stops being code. A **region** is code — only code knows where
 * in the markup a paragraph can go and which facts that spot can supply — and
 * everything inside a region is data.
 *
 * ## A block is one position, in one region, in one language
 *
 * The alternative was one shared list of positions with four bodies hanging off
 * each, and it forces a property nobody asked for: translation parity of
 * *structure*. Dutch and French prose do not decompose into the same number of
 * paragraphs. Worse, there is no fallback here — a block with no body in French
 * renders nothing — so a shared list develops holes that no screen shows you:
 * twelve positions, five empty textareas, and a French page quietly missing a
 * third of its copy. With language on the block, the admin says "French: 0
 * blocks", and `PageRegionsTest` says it louder.
 *
 * ## Two kinds, and a heading opens a section
 *
 * `heading` and `paragraph`, and that is the whole vocabulary. A heading opens a
 * section; the paragraphs after it belong to it. Sections are therefore an
 * *arrangement* rather than a nesting, which is why reordering is one integer
 * and not a tree.
 *
 * Widgets — the related-searches chips — are not a third kind. A widget is a
 * paragraph whose entire body is a single block-level placeholder. See
 * `App\Services\Pages\Placeholders\PlaceholderRegistry`.
 *
 * ## No CHECK on `page` or `region`
 *
 * `kind` gets one because its two values are structural and a third would mean
 * a renderer that does not exist. `page` and `region` do not, deliberately: a
 * value list would need a migration every time a page gains a region, which is
 * exactly the friction this table exists to remove. A row naming a region the
 * code stopped declaring must be **inert, not a failed deploy** — the same call
 * `prompt_templates.slot` made, and for the same reason. It is caught instead by
 * a test asserting every stored pair resolves, and by an "orphaned" badge in the
 * flat admin table, so a rename's casualties are somebody's decision rather than
 * an automatic deletion of copy they wrote.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('page_blocks', function (Blueprint $table) {
            $table->id();

            // Registry keys. See App\Services\Pages\Regions\RegionRegistry.
            $table->string('page');
            $table->string('region');

            /*
             * Language, not market. be-nl and nl-nl differ in catalogue,
             * currency and availability — all of which arrive through
             * placeholders — and share every word. Same reason lang/ is keyed
             * by language.
             */
            $table->string('language', 5);

            $table->string('kind');

            // Reading order within (page, region, language), renumbered from 1
            // on every reorder rather than left with gaps.
            $table->integer('position')->default(0);

            /*
             * Named conditions from the region, **ANDed**.
             *
             * Two ticks means "only when both hold". Written down because the
             * next person will assume OR — and because OR needs a second
             * control, which nobody has asked for and which would make the
             * checkbox list ambiguous the moment it existed.
             *
             * This is where the guards that used to be hardcoded in
             * PageNarrative live now: `$facts['comparable'] > 0 ? ... : null`
             * becomes the `multi_shop` key in this array.
             */
            $table->jsonb('conditions')->default(DB::raw("'[]'::jsonb"));

            /*
             * The reversible hide.
             *
             * Off keeps the row, its position, its variants and its history.
             * Deleting is the destructive one and is confirmed separately.
             * There is no third state — `weight = 0` on a *variant* is the soft
             * retirement of one phrasing, which is a different decision.
             */
            $table->boolean('enabled')->default(true);

            $table->text('note')->nullable();

            $table->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // The read on every render, once per language.
            $table->index(['page', 'region', 'language', 'position']);
        });

        DB::statement("ALTER TABLE page_blocks ADD CONSTRAINT page_blocks_kind_check CHECK (kind IN ('heading', 'paragraph'))");

        Schema::create('page_block_variants', function (Blueprint $table) {
            $table->id();

            $table->foreignId('block_id')->constrained('page_blocks')->cascadeOnDelete();

            $table->text('body');

            /*
             * Relative frequency, not a percentage.
             *
             * Percentages sum to 100, so editing one variant forces edits to its
             * siblings. A weight is local: raise this line and it appears more
             * often, without touching anything else. Zero retires a phrasing
             * without deleting the evidence it existed.
             */
            $table->smallInteger('weight')->default(1);
            $table->boolean('enabled')->default(true);

            $table->text('note')->nullable();

            $table->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['block_id']);
        });

        // A negative weight would silently invert the draw; above 100 is beyond
        // what the admin offers and would only ever be a typo.
        DB::statement('ALTER TABLE page_block_variants ADD CONSTRAINT page_block_variants_weight_check CHECK (weight >= 0 AND weight <= 100)');

        /*
         * No unique index on (block_id, body).
         *
         * Tempting, and it would break: a Postgres btree entry caps at about
         * 2704 bytes and these paragraphs run past it, so the index would refuse
         * rows the application considers perfectly valid. Duplicates are dropped
         * on save instead, and the seeding migration is idempotent by
         * (block, body) in PHP.
         */
    }

    public function down(): void
    {
        Schema::dropIfExists('page_block_variants');
        Schema::dropIfExists('page_blocks');
    }
};
