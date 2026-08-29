<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The instructions given to the writer, editable without a deploy.
 *
 * Every prompt in the application is a heredoc: the column's voice, the guide's
 * hard rules, the way a curator's note is introduced. Changing any of them is a
 * code change and a redeploy — and the person with an opinion about the editorial
 * voice is not the person with Coolify open. Exactly the argument that produced
 * the AI settings screen.
 *
 * ## Shaped like the copy bank, on purpose
 *
 * `copy_templates` solved this for page copy: shipped defaults in code, an
 * optional override in the database, and the table may be empty. Same here. A
 * slot with no row, a blank row or a disabled one resolves to the default, so
 * this table can be empty, half-filled or wrong and every build still produces
 * exactly what it produces today.
 *
 * ## No CHECK on the slot
 *
 * The list of slots lives in code (`PromptBank::SLOTS`), so a row for a slot that
 * no longer exists is inert rather than a way to reach something it should not.
 * A constraint would also mean a migration every time a Cove kind is added,
 * which is precisely the deploy hazard the string-plus-CHECK convention exists
 * to avoid — and unlike a kind, an unknown slot cannot corrupt anything.
 *
 * ## Deliberately not seeded
 *
 * `bc:seed-copy` has a documented trap: a seeded slot shadows the language file,
 * so a later rewrite of the shipped copy becomes invisible. The same trap is
 * worse here, because a stale prompt produces plausible output rather than
 * obviously missing text. The table ships empty; a row exists only when somebody
 * has actually written one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('prompt_templates', function (Blueprint $table) {
            $table->id();

            // One row per slot. `cove.guide`, `gift.angles` — see PromptBank.
            $table->string('slot')->unique();

            /*
             * The two halves, both nullable and independently overridable.
             *
             * Somebody who wants a drier voice edits the system half and leaves
             * the assembly alone; somebody reordering what the model is told
             * edits the user half. Requiring both to be rewritten together would
             * make the small change carry the risk of the large one.
             */
            $table->text('system')->nullable();
            $table->text('user_template')->nullable();

            // The soft off switch, so a rewrite can be parked without losing it.
            $table->boolean('enabled')->default(true);

            $table->text('notes')->nullable();
            $table->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('prompt_templates');
    }
};
