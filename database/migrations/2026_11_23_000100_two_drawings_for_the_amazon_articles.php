<?php

declare(strict_types=1);

use App\Enums\CoveScene;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Two more scenes: `fast_delivery` and `black_friday` (owner, 2026-11-23).
 *
 * The Amazon Prime and Black Friday on Amazon articles showed the generic
 * `article` sheet above them. The enum is checked by the database, so the CHECK
 * on both scene columns widens to the current vocabulary, as
 * 2026_11_08_000400 did before it.
 *
 * Widening only: every value that was legal stays legal and no row is
 * rewritten, so it is safe under expand and contract. `up()` reads the enum
 * because it *is* the current vocabulary; the next scene freezes this list and
 * writes the next migration, as this one froze its predecessor's 68.
 */
return new class extends Migration
{
    /** The 68 scenes allowed before this migration, frozen. */
    public const BEFORE = [
        'coffee', 'cooking', 'racing', 'has_everything', 'dog', 'photography', 'diy', 'outdoors',
        'gardening', 'plants', 'music', 'reading', 'gaming', 'fitness', 'travel', 'baking', 'someone',
        'rights', 'price_history', 'seller', 'reviews', 'refurbished', 'shop_check', 'phishing',
        'customs', 'gift_return', 'missing_parcel', 'money_gift', 'handmade', 'money_tree',
        'folded_note', 'coin_jar', 'treasure_map', 'coupon_book', 'letter', 'plant_cutting',
        'calendar_day', 'cat', 'partner', 'mum', 'dad', 'child', 'baby', 'grandparents', 'siblings',
        'friend', 'colleague', 'host', 'teacher', 'home_office', 'building_blocks', 'crafts',
        'student', 'graduation', 'smart_home', 'sinterklaas', 'gift_card', 'halloween', 'costume',
        'christmas_market', 'wish_list', 'repair', 'private_sale', 'subscription', 'late_delivery',
        'payment', 'trust_mark', 'article',
    ];

    public function up(): void
    {
        $this->constrain(CoveScene::values());
    }

    public function down(): void
    {
        // Only reachable on a database that never held one of the two new values,
        // because the constraint refuses to be created otherwise, and that is the
        // correct failure.
        $this->constrain(self::BEFORE);
    }

    /** @param  list<string>  $values */
    private function constrain(array $values): void
    {
        $allowed = collect($values)->map(fn (string $v) => "'".str_replace("'", "''", $v)."'")->implode(', ');

        foreach (['cove_plans', 'daily_pick_sets'] as $table) {
            DB::statement("alter table {$table} drop constraint if exists {$table}_scene_check");

            DB::statement(
                "alter table {$table} add constraint {$table}_scene_check ".
                "check (scene is null or scene in ({$allowed}))"
            );
        }
    }
};
