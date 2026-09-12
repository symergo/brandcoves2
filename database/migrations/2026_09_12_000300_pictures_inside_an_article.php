<?php

declare(strict_types=1);

use App\Enums\CoveScene;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Ten more scenes: two covers and eight figures for inside an article.
 *
 * Two advice articles written on 2026-09-12 asked for pictures between their
 * paragraphs. An article body renders bold and link tokens and nothing else,
 * so a picture inside one became a `[[figure:KEY]]` token on a paragraph of its
 * own, drawn by the same component and from the same enum as the cover. That
 * enum is checked by the database, so the CHECK on both scene columns widens
 * to the current vocabulary, exactly as 2026_09_05_000300 widened it before.
 *
 * Widening only. Every value that was legal is still legal, no row is
 * rewritten, and it is safe under expand and contract. The list is generated
 * because it *is* the current vocabulary; the next scene freezes it and writes
 * the next migration, as this one froze its predecessor's 28.
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
         * Back to the 28. Only reachable on a database that never held one of
         * the new values, because the constraint refuses to be created
         * otherwise, and that is the correct failure.
         */
        $this->constrain(
            collect([
                'coffee', 'cooking', 'racing', 'has_everything', 'dog',
                'photography', 'diy', 'outdoors', 'gardening', 'plants', 'music',
                'reading', 'gaming', 'fitness', 'travel', 'baking', 'someone',
                'rights', 'price_history', 'seller', 'reviews', 'refurbished',
                'shop_check', 'phishing', 'customs', 'gift_return',
                'missing_parcel', 'article',
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
