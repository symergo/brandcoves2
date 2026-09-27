<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * What a feed listed in the run that is under way: `ingestion_seen_offers`.
 *
 * Until 2026-09-28 every ingest rewrote every offer, and the rewrite set
 * `last_seen_at`, so "not seen this run" was `last_seen_at < run start`. The
 * ingest now writes only the offers that changed (App\Support\ChangedRowsUpsert),
 * so `last_seen_at` stays put on an unchanged offer and can no longer answer
 * that. This table does: IngestFeed adds each chunk's external ids, and at the
 * end retires the feed's active offers that are not in it, with one anti-join.
 *
 * Emptied when a run starts and when it finishes; between runs it is empty.
 * A narrow insert of two columns costs a fraction of rewriting a whole
 * `products` row with its search vector and indexes.
 *
 * UNLOGGED: nothing is written to the WAL for it, which is most of the cost
 * saved. The price is that Postgres empties an unlogged table after a crash
 * (not after a clean shutdown). IngestFeed counts what it recorded in the
 * job's cursor and, if the table holds fewer rows at the end, retires nothing
 * that run rather than retiring what it can no longer prove it saw.
 *
 * No foreign key to `feeds`: the rows live for one run, and a key would add a
 * lookup to every insert. `IF NOT EXISTS`, because a failing migration is an
 * outage (Coolify stops the old containers before `migrate` runs).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE UNLOGGED TABLE IF NOT EXISTS ingestion_seen_offers (
                feed_id bigint NOT NULL,
                external_id text NOT NULL,
                PRIMARY KEY (feed_id, external_id)
            )
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS ingestion_seen_offers');
    }
};
