<?php

declare(strict_types=1);

namespace App\Models;

use App\Services\Social\InviteMailer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Somebody pressed "this is spam" under an invitation from this member.
 *
 * Holds a hash of the complaining address, never the address. One per
 * (member, address). See {@see InviteMailer}.
 *
 * @property int $id
 * @property int $inviter_id
 * @property string $email_hash
 */
class InviteComplaint extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = [];

    /** @return BelongsTo<User, $this> */
    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'inviter_id');
    }
}
