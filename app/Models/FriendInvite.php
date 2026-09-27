<?php

declare(strict_types=1);

namespace App\Models;

use App\Services\Social\FriendInvites;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A connection addressed to somebody who has no account yet.
 *
 * Held rather than acted on, and deliberately indistinguishable from a
 * connection that succeeded — see {@see FriendInvites} for
 * why the two cases must answer the same thing.
 *
 * `birthday_day` / `birthday_month` carry no year and no cast: what one person
 * writes down about another is a day and a month. See the migration.
 *
 * `recipient_id` (2026-09-27): the inviter's saved person the invitation was
 * sent from, linked to the account when this turns into a connection. Null for
 * the plain form, and when that saved person was deleted.
 */
class FriendInvite extends Model
{
    protected $guarded = [];

    /** @return BelongsTo<User, $this> */
    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'inviter_id');
    }
}
