<?php

declare(strict_types=1);

namespace App\Models;

use App\Services\Social\Friends;
use App\Support\DayAndMonth;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One direction of "we share lists".
 *
 * Symmetric in meaning and stored as two rows; see the migration for why, and
 * {@see Friends} for the one place that writes both. Never
 * construct these directly — a single row is half a friendship, and the half
 * that is missing is the one that would have shown you on their page.
 *
 * Not a permission. Access to a list is its share token plus its visibility,
 * and nothing here is consulted to decide who may look at what.
 *
 * `friend_birthday_day` / `_month` carry no year and no cast: it is a day and a
 * month, not a date, and casting it as one would need a year that nobody gave.
 * {@see DayAndMonth} is where the pair is read and written.
 */
class Friendship extends Model
{
    protected $guarded = [];

    /** The person whose friend list this row appears on. */
    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** The person it names. */
    /** @return BelongsTo<User, $this> */
    public function friend(): BelongsTo
    {
        return $this->belongsTo(User::class, 'friend_id');
    }
}
