<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * "I shared this list with this person."
 *
 * One row per name picked in "Share with friends", which is what makes the list
 * appear on that person's friends page. An act, not a setting — the boolean it
 * replaced could not express consent to an audience, because the audience was a
 * set that had accumulated by accident. See the migration.
 *
 * Not a permission. Reaching a list is its share token plus its visibility;
 * this makes it *findable*, and deleting a row takes it off a page rather than
 * taking a link away.
 */
class WishlistShare extends Model
{
    protected $guarded = [];

    /** @return BelongsTo<Wishlist, $this> */
    public function wishlist(): BelongsTo
    {
        return $this->belongsTo(Wishlist::class);
    }

    /** The friend it was shared with. */
    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
