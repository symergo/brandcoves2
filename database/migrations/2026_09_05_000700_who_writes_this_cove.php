<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Who writes this Cove: the builder, or somebody else.
 *
 * Until now the answer was *inferred*, from whether certain fields happened to
 * be non-empty, in three different places that did not agree:
 *
 *   `editorial()`  short-circuits on  filled($plan->editorial)
 *   `article()`    short-circuits on  filled($plan->body) && filled($plan->blurb)
 *
 * The second is the one that shows why inference is the wrong mechanism. An
 * author who sends a finished `body` and no `blurb` gets the model run anyway:
 * real spend against `guide_copy` on a piece already written, and a generated
 * title over the one they chose. Nothing reports it. The author reads the
 * published page to find out.
 *
 * Stating it turns four scattered `filled()` checks into one question, and it
 * is a question somebody should be able to *answer* rather than arrive at by
 * filling in the right combination of boxes — on the curation screen it is a
 * switch, on the editorial API it is a field.
 *
 * ## Why `builder` is the default
 *
 * Because it is what every existing row does. A plan written before this column
 * existed is one the builder writes, and a default of `authored` would tell the
 * builder to publish the empty prose of several hundred plans nobody has
 * touched. The interesting configuration is the new one, and new is what has to
 * be opted into.
 *
 * String plus a CHECK rather than a native Postgres enum, per the convention in
 * CLAUDE.md: altering a PG enum cannot run inside a transaction, which makes
 * every future value a deploy hazard. Cast to `App\Enums\PlanWriter` on the
 * model.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cove_plans', function (Blueprint $table) {
            $table->string('writer', 16)->default('builder');
        });

        DB::statement(
            'ALTER TABLE cove_plans ADD CONSTRAINT cove_plans_writer_check '.
            "CHECK (writer IN ('builder', 'authored'))"
        );

        /*
         * Everything already carrying its own prose was authored, whoever typed
         * it.
         *
         * Without this backfill the column would say `builder` on plans the
         * editorial API wrote, and the first rebuild of each would ask the model
         * to write an article that already exists — the exact spend this column
         * is here to prevent, introduced by the migration that prevents it.
         *
         * Read against the fields the old inference read, so the stored value
         * says what the code was already doing. `blurb` is deliberately not part
         * of the test: a plan with a body and no blurb is precisely the case
         * that was being got wrong, and it was authored too.
         */
        DB::table('cove_plans')
            ->where(fn ($q) => $q
                ->whereNotNull('editorial')->where('editorial', '!=', '')
                ->orWhere(fn ($w) => $w->whereNotNull('body')->where('body', '!=', '')))
            ->update(['writer' => 'authored']);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE cove_plans DROP CONSTRAINT IF EXISTS cove_plans_writer_check');

        Schema::table('cove_plans', function (Blueprint $table) {
            $table->dropColumn('writer');
        });
    }
};
