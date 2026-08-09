<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * One participant, and — once drawn — who they are buying for.
 */
class SecretSantaMember extends Model
{
    protected $guarded = [];

    /**
     * The pairing never leaves the server by accident.
     *
     * `$hidden` is belt to the encryption's braces: the column is encrypted at
     * rest so a database dump is useless, and hidden from serialisation so an
     * `->toArray()` on a member list cannot put the whole game in a payload.
     * Both, because either one alone is a single edit away from failing.
     */
    protected $hidden = ['assigned_member_id'];

    protected function casts(): array
    {
        return [
            'assigned_member_id' => 'encrypted',
            'exclusions' => 'array',
            'joined_at' => 'datetime',
            'marked_done_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $member): void {
            // The credential a member without an account uses to read their own
            // assignment. Generated here so no path can create a member who
            // cannot be told who they drew.
            $member->join_token ??= (string) Str::uuid();
        });
    }

    /** @return BelongsTo<SecretSantaGroup, $this> */
    public function group(): BelongsTo
    {
        return $this->belongsTo(SecretSantaGroup::class, 'group_id');
    }

    /** @return BelongsTo<Wishlist, $this> */
    public function wishlist(): BelongsTo
    {
        return $this->belongsTo(Wishlist::class, 'wishlist_id');
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function hasDrawn(): bool
    {
        return $this->assigned_member_id !== null;
    }
}
