<?php

declare(strict_types=1);

namespace App\Services\Social;

use App\Models\Friendship;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The people you share lists with, and the only place both directions are written.
 *
 * A friendship is symmetric and stored as two rows, so every write is two
 * writes and every removal is two removals. Doing that at the call site would
 * work until the second call site, at which point one of them would create a
 * friendship visible to one person only — a name that appears on your page and
 * not on theirs, which reads as a bug on whichever side is missing it.
 *
 * ## Where friendships come from
 *
 * Following somebody's shared list link and having an account at the end of it.
 * That is a deliberately low bar and it is the honest one: sharing is a link,
 * links get forwarded, and the person now holding your registry *is* somebody
 * you are coordinating with whether or not you picked them from a list. What
 * keeps that fair is that it is symmetric — both people see the connection, at
 * the same moment — and that either of them can remove it, which removes it for
 * both.
 *
 * ## What it is not
 *
 * Not a permission (nothing reads it to decide who may see a list), and not
 * claim state. Being connected to somebody says they hold a link, which they
 * already knew, and says nothing whatever about what has been claimed. Keeping
 * it that way is the reason this class knows nothing about `wishlist_items`.
 */
class Friends
{
    /**
     * Connect two people, in both directions, once.
     *
     * Idempotent by upsert rather than by a read: two tabs finishing a sign-in
     * at the same moment is a race the unique index would otherwise turn into a
     * 500 on the login itself.
     */
    public function link(User $a, User $b, string $source = 'shared_list'): void
    {
        // Guarded here as well as by the CHECK constraint: a self-link is a
        // caller mistake worth swallowing rather than a 500 on somebody's
        // sign-in, and the commonest caller is "the owner opened their own
        // link", which is not an error at all.
        if ($a->id === $b->id) {
            return;
        }

        $now = now();

        DB::table('friendships')->upsert(
            [
                ['user_id' => $a->id, 'friend_id' => $b->id, 'source' => $source, 'created_at' => $now, 'updated_at' => $now],
                ['user_id' => $b->id, 'friend_id' => $a->id, 'source' => $source, 'created_at' => $now, 'updated_at' => $now],
            ],
            ['user_id', 'friend_id'],
            /*
             * Nothing on conflict but the timestamp.
             *
             * `source` is how the two *first* met, and a second meeting does not
             * rewrite the first. `created_at` stays put for the same reason
             * `ListOpen` never updates `first_opened_at`.
             */
            ['updated_at' => $now],
        );
    }

    /**
     * Disconnect, in both directions.
     *
     * Symmetric on purpose, and it is the part most likely to be argued with
     * later: removing somebody could plausibly leave you on their page. It must
     * not. The connection is a single fact about two people, and a version of
     * it that one of them can still see after the other has ended it is exactly
     * the shape of thing people mean when they say a site kept their data.
     */
    public function unlink(User $user, int $friendId): void
    {
        Friendship::query()
            ->where(fn ($q) => $q->where('user_id', $user->id)->where('friend_id', $friendId))
            ->orWhere(fn ($q) => $q->where('user_id', $friendId)->where('friend_id', $user->id))
            ->delete();
    }

    /**
     * This person's friends, newest connection first.
     *
     * @return Collection<int, Friendship>
     */
    public function forUser(User $user): Collection
    {
        return Friendship::query()
            ->with('friend')
            ->where('user_id', $user->id)
            ->latest()
            ->get();
    }
}
