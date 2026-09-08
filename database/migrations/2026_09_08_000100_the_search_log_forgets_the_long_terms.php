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
 * `SearchLog::record()` refuses the long ones from now on (see the two
 * constants on the model), and a crawler's request is no longer logged at
 * all. What is already there is another matter: the crawler walked every
 * length, and the two- to six-word steps of that walk ("apple find bluetooth
 * tracker grey") cannot be told from a person's query by any rule. So the
 * owner's decision on 2026-09-08 was to keep only the single words, which
 * are the one shape no crawler minted, and let the log fill up again with
 * what people actually type from here on. Measured before the deploy:
 * 1,126,485 of 1,149,744 rows go, 23,259 single words stay.
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
            ->orWhereRaw('array_length(regexp_split_to_array(query, ?), 1) > 1', ['\s+'])
            ->delete();

        echo "search_log: {$deleted} multi-word terms removed\n";
    }

    public function down(): void
    {
        // Deleted rows are gone; nothing to restore.
    }
};
