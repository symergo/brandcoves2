<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The tagging queue's "new" count, from the index alone (2026-11-22).
 *
 * App\Services\Gift\TaggingQueue counts giftable products nobody judged, first seen in a period.
 * The only index for it was (market, first_seen_at), so every candidate row was read from the
 * table to check `giftable` and `gift_tags_at`: on production that was 2.8 s for be-nl over 7
 * days (53,000 table pages) and 20 to 34 s over a year, with eight such counts on each refresh
 * of Catalogue > Product tagging. Two of them ended in the 30 s timeout.
 *
 * Partial on exactly the queue's condition, so it holds only what is waiting and shrinks as
 * products are judged. Built CONCURRENTLY so a deploy does not hold writes on a table of four
 * million rows; hence no transaction.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS product_groups_tagging_queue_idx ON product_groups (market, first_seen_at) WHERE giftable = true AND gift_tags_at IS NULL');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS product_groups_tagging_queue_idx');
    }
};
