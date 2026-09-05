<?php

declare(strict_types=1);

use App\Enums\CoveScene;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Widen the scene vocabulary: eight more personas, and a subject for an article.
 *
 * `2026_08_31_000200_a_persona_names_its_own_drawing` gave a persona a drawing
 * and allowed nine values. Two things have since made that too narrow.
 *
 * **The persona shelves grew past nine kinds of person.** Ten personas per
 * market, and gardeners, plant owners, readers, listeners, gamers and travellers
 * are not any of coffee, cooking, racing, has-everything, dogs, photography, DIY
 * or outdoors. Every one of them would have fallen back to `someone`, which is a
 * portrait meaning "we did not say" — six of those on one shelf is a shelf that
 * looks unfinished.
 *
 * **And `/guides` had no pictures at all.** In `be-nl`, `nl-nl` and `en` that
 * page is entirely Advice Coves — eight articles about how to shop — and it
 * rendered as eight identical rectangles of text. An article has no photograph
 * for the same reason a persona has none: its substance is writing. So it names
 * a subject and `SceneIllustration` draws that, in the same language.
 *
 * ## One column, and therefore one enum
 *
 * The two vocabularies do not overlap — a persona is a kind of *person*, an
 * article is a kind of *subject* — but they share `scene` on both tables, and a
 * column holds one type. So {@see CoveScene} holds both and
 * {@see CoveScene::forKind()} decides which half a kind may name. The CHECK is
 * the union, deliberately: constraining scene-to-kind in SQL would need a
 * conditional CHECK rewritten every time a kind gains a vocabulary, and the
 * question "may this kind say that" is already answered in one place in PHP,
 * where the planner and the API both ask it.
 *
 * ## Generated from the enum, and this one may be
 *
 * The earlier migration was corrected in the same change to write its nine
 * values out literally, because generating them from a growing enum made a
 * migration whose meaning changed after it had run. This one generates them
 * because it is the migration that *is* the current vocabulary — and the moment
 * a case is added it must itself be frozen and a new widening written, exactly
 * as this one froze its predecessor. If you are here to add a scene: freeze the
 * list below and add another migration.
 *
 * Widening only. Every value that was legal before is still legal, so nothing
 * has to be rewritten and the constraint can be swapped without touching a row —
 * which is what makes this safe to run against a live table under expand and
 * contract.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->constrain($this->allowedList());
    }

    public function down(): void
    {
        /*
         * Back to the nine. Only reachable on a database that never held one of
         * the new values — the constraint refuses to be created otherwise, which
         * is the correct failure: a rollback that silently blanked the drawing
         * on thirty Coves would be worse than one that stops.
         */
        $this->constrain(
            collect([
                'coffee', 'cooking', 'racing', 'has_everything', 'dog',
                'photography', 'diy', 'outdoors', 'someone',
            ])->map(fn (string $v) => "'".$v."'")->implode(', ')
        );
    }

    private function constrain(string $allowed): void
    {
        foreach (['cove_plans', 'daily_pick_sets'] as $table) {
            DB::statement("alter table {$table} drop constraint if exists {$table}_scene_check");

            DB::statement(
                "alter table {$table} add constraint {$table}_scene_check ".
                "check (scene is null or scene in ({$allowed}))"
            );
        }
    }

    private function allowedList(): string
    {
        return collect(CoveScene::values())
            ->map(fn (string $v) => "'".str_replace("'", "''", $v)."'")
            ->implode(', ');
    }
};
