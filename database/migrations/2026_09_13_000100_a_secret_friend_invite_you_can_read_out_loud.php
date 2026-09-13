<?php

declare(strict_types=1);

use App\Support\ShareCode;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * `secret_santa_groups.invite_token` stops being a uuid column, so a new group
 * can carry a ten-character code and its invite can be `/be-nl/s/k7m2xq9v4p`.
 *
 * The link the organiser shares was `/be-nl/santa/{uuid}/join/{uuid}`: about
 * ninety characters next to a wish list's `/be-nl/l/k7m2xq9v4p`, and the one
 * link on the site that is pasted into a group chat by hand. Same reasoning as
 * `2026_09_06_000600_a_share_link_you_can_read_out_loud`; see {@see ShareCode}.
 *
 * ## Existing tokens are kept, deliberately
 *
 * The wishlist migration regenerated every row and broke every link already
 * sent, and said so. Here the rows are few and every one of them is an invite
 * sitting in somebody's WhatsApp mid-exchange, so the old uuids stay as they
 * are: the old route keeps resolving them, and the new short route accepts a
 * uuid too (`ShareCode::pattern()` is generous on purpose). Only groups made
 * from now on get the short code. Two shapes of token in one column is the
 * price, and it is paid once, by this comment.
 *
 * `USING` is required: Postgres will not cast uuid to varchar implicitly.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE secret_santa_groups ALTER COLUMN invite_token TYPE varchar(40) USING invite_token::text');
    }

    public function down(): void
    {
        // Codes made since cannot become uuids; give them one so the type
        // change succeeds. Those invites break, which is what a rollback of
        // this migration means.
        DB::statement("UPDATE secret_santa_groups SET invite_token = gen_random_uuid()::text WHERE invite_token !~ '^[0-9a-f-]{36}$'");
        DB::statement('ALTER TABLE secret_santa_groups ALTER COLUMN invite_token TYPE uuid USING invite_token::uuid');
    }
};
