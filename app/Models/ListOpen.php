<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Owner;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
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
