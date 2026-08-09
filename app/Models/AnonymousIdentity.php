<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\AnonymousIdentityFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A visitor who has not signed up.
 *
 * The gift wizard has to be useful before anyone creates an account — demanding
 * a login before showing results is how you lose the visit. A signed cookie
 * carries this id, and everything built under it is merged into the user's
 * account at signup.
 */
class AnonymousIdentity extends Model
{
    /** @use HasFactory<AnonymousIdentityFactory> */
    use HasFactory;

    use HasUuids;

    protected $table = 'anonymous_identities';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'merged_at' => 'datetime',
            'last_seen_at' => 'datetime',
        ];
    }

    /** @return HasMany<Wishlist, $this> */
    public function wishlists(): HasMany
    {
        return $this->hasMany(Wishlist::class, 'owner_anon_id');
    }

    /** @return HasMany<Recipient, $this> */
    public function recipients(): HasMany
    {
        return $this->hasMany(Recipient::class, 'owner_anon_id');
    }

    /** @return BelongsTo<User, $this> */
    public function mergedInto(): BelongsTo
    {
        return $this->belongsTo(User::class, 'merged_into_user_id');
    }

    public function isMerged(): bool
    {
        return $this->merged_into_user_id !== null;
    }
}
