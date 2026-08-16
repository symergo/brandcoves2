<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CollaboratorRole;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * "Help me choose something for Mum."
 *
 * Exists because the person whose help you want is usually the person who has
 * not signed up yet — and until this table, inviting them did nothing at all
 * while telling the owner it had worked.
 *
 * @property CollaboratorRole $role
 */
class ListInvitation extends Model
{
    /**
     * A fortnight.
     *
     * Long enough to survive a holiday and an unread inbox; short enough that a
     * link found in an old mailbox two years later does not still open somebody
     * else's gift list. The list itself is usually finished well inside it.
     */
    public const LIFETIME_DAYS = 14;

    protected $guarded = [];

    protected static function booted(): void
    {
        static::creating(function (self $invitation): void {
            $invitation->token ??= (string) Str::uuid();
            $invitation->expires_at ??= now()->addDays(self::LIFETIME_DAYS);
            // Lowercased here rather than at the call site: the claim path
            // matches on it, and one uppercase letter would silently orphan the
            // invitation.
            $invitation->email = mb_strtolower(trim((string) $invitation->email));
        });
    }

    protected function casts(): array
    {
        return [
            'role' => CollaboratorRole::class,
            'expires_at' => 'datetime',
            'claimed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Wishlist, $this> */
    public function wishlist(): BelongsTo
    {
        return $this->belongsTo(Wishlist::class);
    }

    /** @return BelongsTo<User, $this> */
    public function invitedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by_user_id');
    }

    public function isOpen(): bool
    {
        return $this->claimed_at === null && $this->expires_at->isFuture();
    }

    /** @param Builder<$this> $query */
    public function scopeOpen(Builder $query): void
    {
        $query->whereNull('claimed_at')->where('expires_at', '>', now());
    }
}
