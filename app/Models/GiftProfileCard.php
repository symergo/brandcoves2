<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Market;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * "My gift profile": what This or that learned about somebody, made into a
 * link they chose to share. Opening it seeds Find a gift.
 *
 * Holds the profile only, never the choices it came from, and a name only
 * when the maker typed one. `owner_key_hash` is how an anonymous maker takes
 * the card down again; it is `$hidden`, with the owner, so neither reaches a
 * page. See docs/features/gift-profile-card.md.
 */
class GiftProfileCard extends Model
{
    protected $guarded = [];

    protected $hidden = ['owner_key_hash', 'owner_user_id'];

    protected function casts(): array
    {
        return [
            'market' => Market::class,
            'profile' => 'array',
            'last_opened_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }
}
