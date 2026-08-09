<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

/**
 * Stop one unbuildable topic from blocking the whole Cove queue.
 *
 * ## The failure
 *
 * `ripest()` returned "kamperen" every time. `GuideBuilder::build()` looked for
 * products, found four where it needs five, logged "too few products" and
 * returned null. The edition fell back to an existing guide, and the next day the
 * exact same thing happened — because nothing recorded that the attempt had been
 * made.
 *
 * On staging that showed as a queue of 123 topics and five Coves, with the same
 * topic at the head of it forever. Every other topic was unreachable behind one
 * that could never be built.
 *
 * It was invisible in tests because a test either seeds enough products or
 * asserts the skip; nothing asserted what happens *the next day*.
 *
 * ## The fix
 *
 * Record the attempt. `ripest()` then skips a topic it has tried recently, which
 * lets the one behind it through.
 *
 * Fourteen days, not one: a topic is thin because the catalogue is thin, feeds
 * refresh twice a day but a category's *breadth* changes on the scale of weeks,
 * and retrying nightly would spend the slot re-discovering the same shortfall.
 * Fourteen days is short enough that a new advertiser's feed is noticed within a
 * fortnight and long enough that the queue keeps moving.
 *
 * `attempts` is kept as well as the timestamp because it is the number that makes
 * a permanent gap legible in admin: a topic on its ninth failed attempt is not
 * waiting for stock, it is a topic whose products we do not sell.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('guide_topics', function (Blueprint $table) {
            $table->timestampTz('last_attempt_at')->nullable()->after('score');
            $table->smallInteger('attempts')->default(0)->after('last_attempt_at');
        });
    }

    public function down(): void
    {
        Schema::table('guide_topics', function (Blueprint $table) {
            $table->dropColumn(['last_attempt_at', 'attempts']);
        });
    }
};
