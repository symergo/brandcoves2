<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Create `pg_stat_statements`, so Postgres records what each query costs.
 *
 * The speed audit of 2026-09-27 had to find the slow queries from table
 * counters and EXPLAIN, because nothing recorded query times. The library is
 * loaded by `shared_preload_libraries` in docker-compose.coolify.yml (a
 * database restart, which the owner agreed to); this creates the view to read
 * it: `SELECT calls, mean_exec_time, total_exec_time, query FROM
 * pg_stat_statements ORDER BY total_exec_time DESC LIMIT 25`.
 *
 * Guarded, because a failing migration is an outage (Coolify stops the old
 * containers before `migrate` runs): creating this extension needs a
 * superuser, and an environment where the role is not one, or the extension is
 * not installed, simply goes without. Nothing in the application reads it.
 */
return new class extends Migration
{
    public function up(): void
    {
        $available = DB::selectOne("SELECT 1 AS ok FROM pg_available_extensions WHERE name = 'pg_stat_statements'");
        $superuser = DB::selectOne('SELECT rolsuper AS ok FROM pg_roles WHERE rolname = current_user');

        if ($available === null || ! ($superuser?->ok ?? false)) {
            Log::info('pg_stat_statements not created: not available or not a superuser');

            return;
        }

        DB::statement('CREATE EXTENSION IF NOT EXISTS pg_stat_statements');
    }

    public function down(): void
    {
        DB::statement('DROP EXTENSION IF EXISTS pg_stat_statements');
    }
};
