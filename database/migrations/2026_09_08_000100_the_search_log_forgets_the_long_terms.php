<?php

declare(strict_types=1);

use App\Models\SearchLog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Delete the long terms from the search log, once.
 *
 * `search_log` is the site's demand signal: it decides which buying guides
 * get written and what the popular-searches page prints. On 2026-09-08 it
 * held 1.14 million rows, and 950 thousand of them were queries such as
 * "koptelefoon sound draadloze uur hoofdtelefoon hoofdtelefoons earpads hoge
 * dichtheid drivers bluetooth blue true core code speelduur" - crawlers
 * walking the term chips that used to narrow cumulatively, minting a new
 * query at every step. The chips went on 2026-09-05; ten thousand such rows
 * a day were still arriving from crawlers revisiting the URLs they had
 * already found.
 *
 * `SearchLog::record()` refuses them from now on (see the two constants on
 * the model for the rule and why those numbers). This removes what is
 * already there, with the same rule, so the log and the rule agree.
 *
 * A plain DELETE rather than a batched one. The table is written by upsert
 * only and read by jobs and one cached page, so nothing user-facing waits on
 * the lock, and a million-row delete on this schema is a matter of seconds to
 * a minute. Staging runs it first, as with every migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        $deleted = DB::table('search_log')
            ->whereRaw('length(query) > ?', [SearchLog::MAX_LENGTH])
            ->orWhereRaw('array_length(regexp_split_to_array(query, ?), 1) > ?', ['\s+', SearchLog::MAX_WORDS])
            ->delete();

        echo "search_log: {$deleted} long terms removed\n";
    }

    public function down(): void
    {
        // Deleted rows are gone; nothing to restore.
    }
};
