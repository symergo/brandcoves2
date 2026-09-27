<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ListVisibility;
use App\Support\Owner;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * "Somebody followed the link to this list."
 *
 * What makes a shared list findable again once the message carrying it is
 * gone — and, since sharing became a link rather than a list of invited
 * addresses, the only thing that puts anything under Shared Lists.
 *
 * Not a permission. Access to a shared list is the token plus
 * `visibility != private`, exactly as before, and `SharedListController` still
 * decides that on its own. Turning sharing off takes the list away from
 * everybody who ever opened it, which is what "turning sharing off" has to
 * mean — so this is a bookmark, not a grant, and nothing reads it to decide
 * whether somebody may look.
 */
class ListOpen extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'first_opened_at' => 'datetime',
            'last_opened_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Wishlist, $this> */
    public function wishlist(): BelongsTo
    {
        return $this->belongsTo(Wishlist::class);
    }

    /**
     * {@see record()} from the shared page, at most once an hour per reader
     * and list.
     *
     * The shared page is read far more often than it changes hands: the same
     * few people open one list many times in a week, and each open rewrote
     * this row only to move `last_opened_at` by minutes. Nothing reads that
     * column to within an hour (it orders "recently opened"), so a repeat
     * inside the hour is skipped on a cache marker. The first open always
     * writes, which is the one that grants the bookmark.
     */
    public static function recordFromRead(Wishlist $list, Owner $reader): void
    {
        $attributes = $reader->attributes('user_id', 'anon_id');
        $who = $attributes['user_id'] !== null ? 'u'.$attributes['user_id'] : 'a'.$attributes['anon_id'];

        if ($attributes['user_id'] === null && $attributes['anon_id'] === null) {
            return;
        }

        if (! Cache::add("list-open:{$list->id}:{$who}", true, 3600)) {
            return;
        }

        self::record($list, $reader);
    }

    /** Session key for {@see rememberForLater()}. */
    private const PENDING_SESSION_KEY = 'list_opens_pending';

    /** Enough for the lists one person is sent in a sitting; the rest are dropped. */
    private const PENDING_LIMIT = 10;

    /**
     * Hold an open by a guest who has no identity yet, in their session.
     *
     * Since 2026-09-27 the shared page makes no identity on a read, so there is
     * nobody to record the open against. It is kept here and recorded by
     * {@see recordRemembered()} when a write makes the identity, so a guest
     * who opens a link and then does something (a suggestion, a vote, saving
     * a product anywhere) still finds the list under their lists. A guest who
     * only reads keeps nothing, which is the point: most of them are link
     * previews and people passing through.
     */
    public static function rememberForLater(Request $request, Wishlist $list): void
    {
        if (! $request->hasSession()) {
            return;
        }

        /** @var list<int> $ids */
        $ids = (array) $request->session()->get(self::PENDING_SESSION_KEY, []);

        if (in_array($list->id, $ids, true)) {
            return;
        }

        $ids[] = $list->id;
        $request->session()->put(self::PENDING_SESSION_KEY, array_slice($ids, -self::PENDING_LIMIT));
    }

    /** Record the opens {@see rememberForLater()} held, now that there is somebody to own them. */
    public static function recordRemembered(Request $request, Owner $reader): void
    {
        if (! $request->hasSession() || ! $reader->exists()) {
            return;
        }

        /** @var list<int> $ids */
        $ids = (array) $request->session()->pull(self::PENDING_SESSION_KEY, []);

        if ($ids === []) {
            return;
        }

        // Only lists still shared: one switched off since is not theirs to find.
        Wishlist::query()
            ->whereKey($ids)
            ->where('visibility', '!=', ListVisibility::Private->value)
            ->get()
            ->each(fn (Wishlist $list) => self::record($list, $reader));
    }

    /**
     * Record that this person has the list in front of them.
     *
     * An upsert on the partial unique index rather than a read-then-write: a
     * reader refreshing, or two tabs opening at once, must not accumulate rows,
     * and `updateOrCreate` loses that race. `first_opened_at` is deliberately
     * not in the update list — it answers "when did this list come into my
     * world", which does not change.
     *
     * Silent when there is nobody to attribute it to. A visitor with no
     * identity at all has nowhere to keep a bookmark, and minting one just to
     * record a page view would be tracking rather than a convenience.
     */
    public static function record(Wishlist $list, Owner $reader): void
    {
        $attributes = $reader->attributes('user_id', 'anon_id');

        if ($attributes['user_id'] === null && $attributes['anon_id'] === null) {
            return;
        }

        $now = now();

        DB::table('list_opens')->upsert(
            [[
                'wishlist_id' => $list->id,
                ...$attributes,
                'first_opened_at' => $now,
                'last_opened_at' => $now,
            ]],
            /*
             * All three columns, matching the one unique index.
             *
             * Naming only the column that is set — `['wishlist_id','user_id']`
             * — needs a unique index on exactly that pair, and a *partial* one
             * does not satisfy Postgres unless the statement repeats its
             * `WHERE`. That 500'd every shared list until the index became a
             * single `NULLS NOT DISTINCT` triple.
             */
            ['wishlist_id', 'user_id', 'anon_id'],
            ['last_opened_at' => $now],
        );
    }
}
