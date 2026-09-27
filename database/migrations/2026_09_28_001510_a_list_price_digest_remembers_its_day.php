<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * `users.list_digest_on`: the day a person's list price digest was last done.
 *
 * SendListPriceDigests read every watched list into memory at once and mailed
 * as it went, so a run cut off halfway (a deploy, a timeout) started from the
 * top on its retry: the first half of the owners were processed twice. The job
 * now walks owners in chunks and skips anyone whose digest is already done
 * today, which this column records.
 *
 * Expand only: a nullable column nothing else reads, added with IF NOT EXISTS,
 * because a failing migration is an outage (Coolify stops the old containers
 * before `migrate` runs). Adding a nullable column without a default is a
 * catalogue change in Postgres, not a table rewrite.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE users ADD COLUMN IF NOT EXISTS list_digest_on date');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE users DROP COLUMN IF EXISTS list_digest_on');
    }
};
