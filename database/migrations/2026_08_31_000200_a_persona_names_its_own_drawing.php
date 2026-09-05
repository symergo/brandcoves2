<?php

declare(strict_types=1);

use App\Enums\CoveScene;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A gift persona names the picture that goes with it.
 *
 * The shelf at `/gift-ideas` used the first buyable product's photograph as
 * each persona's cover. Wrong picture, twice: it makes a page about a *person*
 * look like a product category, and the cover changes whenever stock does — the
 * same persona wearing a different face from one week to the next, for a reason
 * no reader can see and no editor chose.
 *
 * So the persona names a scene and `SceneIllustration` draws it, in the same
 * language as the homepage cards. See {@see CoveScene} for why
 * there are a handful of them rather than one per persona.
 *
 * ## Both tables, because the plan is the source and the edition is the page
 *
 * `cove_plans` is what a curator edits and what the editorial API writes;
 * `daily_pick_sets` is what the builder produces and what the site renders.
 * Every other authored field on a persona — title, blurb, editorial — lives on
 * both and is copied across by `EditionBuilder::buildPersona()`. A scene on the
 * plan alone would be a field you could set and never see; on the edition alone
 * it would be overwritten on the next rebuild.
 *
 * ## Nullable, and null is not an error
 *
 * Every persona written before this has no scene, and none of them should have
 * been blocked from rendering over a drawing. Null reads as
 * {@see CoveScene::Someone} — a portrait — so the shelf never shows
 * a hole.
 *
 * String plus a CHECK rather than a native PG enum, per CLAUDE.md: `ALTER TYPE
 * ... ADD VALUE` cannot run inside a transaction, which would make every future
 * scene a deploy hazard.
 *
 * **The nine values are written out rather than read from the enum, and that
 * is a correction.** This migration originally generated the list from
 * `PersonaScene::values()`, which meant its meaning changed every time
 * somebody added a case: a fresh `migrate` would build a constraint the
 * deployed databases had never had, so the schema a test ran against and the
 * schema production held would silently disagree — and the disagreement would
 * be invisible until a row that passed locally was refused on deploy. A
 * migration is a record of what was done on a day. Widening is what the next
 * migration is for; see `2026_09_05_000300_the_articles_get_a_picture_too`.
 *
 * Not constrained to `kind = 'persona'`. A scene is meaningless on a Daily and
 * harmless there, and a conditional CHECK would have to be rewritten the first
 * time somebody wants a drawing on a Shop Cove.
 */
return new class extends Migration
{
    public function up(): void
    {
        $allowed = $this->allowedList();

        foreach (['cove_plans', 'daily_pick_sets'] as $table) {
            Schema::table($table, function (Blueprint $t): void {
                $t->string('scene', 32)->nullable();
            });

            DB::statement(
                "alter table {$table} add constraint {$table}_scene_check ".
                "check (scene is null or scene in ({$allowed}))"
            );
        }
    }

    public function down(): void
    {
        foreach (['cove_plans', 'daily_pick_sets'] as $table) {
            DB::statement("alter table {$table} drop constraint if exists {$table}_scene_check");

            Schema::table($table, function (Blueprint $t): void {
                $t->dropColumn('scene');
            });
        }
    }

    /** The nine scenes that existed on 2026-08-31, frozen. See the class note. */
    private function allowedList(): string
    {
        return collect([
            'coffee', 'cooking', 'racing', 'has_everything', 'dog',
            'photography', 'diy', 'outdoors', 'someone',
        ])->map(fn (string $v) => "'".$v."'")->implode(', ');
    }
};
