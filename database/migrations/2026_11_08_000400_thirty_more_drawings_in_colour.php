<?php

declare(strict_types=1);

use App\Enums\CoveScene;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Thirty more scenes, drawn with the colour (owner, 2026-11-08).
 *
 * The drawings went from ink lines with an accent wash to flat colour fills,
 * and the personas and articles that fell back to `someone` or `article`
 * because nothing drawn fitted them got drawings of their own: the relation
 * personas, new parents, the cat person, the home worker, and the seasonal
 * and newer advice articles. The enum is checked by the database, so the CHECK
 * on both scene columns widens to the current vocabulary, exactly as
 * 2026_09_12_000300 widened it before.
 *
 * Widening only. Every value that was legal is still legal, no row is
 * rewritten, and it is safe under expand and contract. The list is generated
 * because it *is* the current vocabulary; the next scene freezes it and writes
 * the next migration, as this one froze its predecessor's 38.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->constrain($this->allowedList());
    }

    public function down(): void
    {
        // Back to the 38. Only reachable on a database that never held one of
        // the new values, because the constraint refuses to be created
        // otherwise, and that is the correct failure.
        $this->constrain(
            collect([
                'coffee', 'cooking', 'racing', 'has_everything', 'dog',
                'photography', 'diy', 'outdoors', 'gardening', 'plants', 'music',
                'reading', 'gaming', 'fitness', 'travel', 'baking', 'someone',
                'rights', 'price_history', 'seller', 'reviews', 'refurbished',
                'shop_check', 'phishing', 'customs', 'gift_return',
                'missing_parcel', 'money_gift', 'handmade', 'money_tree',
                'folded_note', 'coin_jar', 'treasure_map', 'coupon_book',
                'letter', 'plant_cutting', 'calendar_day', 'article',
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
