<?php

declare(strict_types=1);

use App\Support\ShareCode;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * `wishlists.share_token` stops being a uuid and becomes a ten-character code.
 *
 * `/be-nl/l/01a05ec8-535d-717b-bc3b-644da5bdbc78` becomes
 * `/be-nl/l/k7m2xq9v4p`. See {@see ShareCode} for why ten and which alphabet.
 *
 * ## Every existing link stops working, and that was the decision
 *
 * The token is regenerated for every row. A link already sitting in somebody's
 * WhatsApp will 404 after this deploy — chosen deliberately over carrying a
 * second column and resolving both, because two addresses for one page is a
 * thing every later feature has to remember.
 *
 * The blast radius is real and worth stating: shared lists, gift lists and group
 * gifts all reach their members through this token, and the members mostly have
 * no account to find the list any other way. **Announce it, or re-send the links,
 * before deploying to production.** `list_opens` and `wishlist_shares` mean the
 * friends page and My Lists still hold the list for anybody who had opened or
 * been sent it, so signed-in members are not stranded — anonymous ones are.
 *
 * ## The column type is the other half
 *
 * It was a **native `uuid`**, and that is why `routes/web.php` carries three
 * hand-written `[0-9a-fA-F-]{36}` constraints: an unconstrained `{token}`
 * reached Postgres as `where share_token = 'suggest'` and raised `22P02:
 * invalid input syntax for type uuid`, so a mistyped path 500'd instead of
 * 404ing. On `varchar` that whole class of failure is gone — a stray path is
 * simply zero rows.
 *
 * `recipients.share_token` and `list_quizzes.share_token` are still uuid
 * columns and still need their constraints. They can move to `ShareCode` the
 * same way when somebody wants shorter links for them too; the helper exists so
 * that is one line rather than a second implementation.
 */
return new class extends Migration
{
    public function up(): void
    {
        // uuid → text first, so the new codes have somewhere to go. `USING` is
        // required: Postgres will not cast uuid to varchar implicitly.
        DB::statement('ALTER TABLE wishlists ALTER COLUMN share_token TYPE varchar(40) USING share_token::text');

        /*
         * Regenerated in PHP, one row at a time, because the alphabet and the
         * CSPRNG both live in `ShareCode` and a second implementation in SQL is
         * exactly the drift this migration exists to remove.
         *
         * Chunked: this runs against production, where the table is not small,
         * and a single `get()` of every list to rewrite one column each is a
         * needless spike in a deploy step that blocks the app containers.
         */
        DB::table('wishlists')->orderBy('id')->chunkById(500, function ($lists): void {
            foreach ($lists as $list) {
                DB::table('wishlists')
                    ->where('id', $list->id)
                    ->update(['share_token' => $this->uniqueCode()]);
            }
        });
    }

    public function down(): void
    {
        /*
         * Deliberately not reversible in a way that restores the old tokens:
         * they are gone, and inventing new uuids would leave every link broken
         * a second time. Rolling back gives the column its old *type* so a
         * re-run of `up()` is possible, and nothing more.
         */
        DB::statement('UPDATE wishlists SET share_token = gen_random_uuid()::text');
        DB::statement('ALTER TABLE wishlists ALTER COLUMN share_token TYPE uuid USING share_token::uuid');
    }

    /**
     * A code no other row is using.
     *
     * At 50 bits a collision is vanishingly unlikely, but the column is unique
     * and a migration that dies two thousand rows in is worse than a loop that
     * almost never runs twice.
     */
    private function uniqueCode(): string
    {
        do {
            $code = ShareCode::make();
        } while (DB::table('wishlists')->where('share_token', $code)->exists());

        return $code;
    }
};
