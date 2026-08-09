<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\EventType;
use App\Enums\ListKind;
use App\Enums\ListVisibility;
use App\Enums\Market;
use App\Support\Owner;
use Database\Factories\WishlistFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * A list, either for yourself or for a specific recipient.
 *
 * @property ListVisibility $visibility
 * @property ListKind $kind
 */
class Wishlist extends Model
{
    /** @use HasFactory<WishlistFactory> */
    use HasFactory;

    use HasUuids;

    protected $guarded = [];

    protected static function booted(): void
    {
        // The column is NOT NULL and every list needs a share URL. Generating it
        // here rather than at the call site means no code path can create a list
        // that cannot be shared.
        static::creating(function (self $list): void {
            $list->share_token ??= (string) Str::uuid();
        });
    }

    protected function casts(): array
    {
        return [
            'market' => Market::class,
            'visibility' => ListVisibility::class,
            'kind' => ListKind::class,
            'event_type' => EventType::class,
            'event_date' => 'date',
            'is_default' => 'boolean',
            'handed_over_at' => 'datetime',

            /*
             * A home address is the most sensitive thing this application
             * holds, and unlike an email it cannot be rotated. Encrypted at
             * rest so a database copy — a backup, a laptop, a support session —
             * is not a list of where people live.
             */
            'delivery_address' => 'encrypted',
        ];
    }

    /** A registry is an ordinary list with an occasion attached. */
    public function isRegistry(): bool
    {
        return $this->event_type !== null;
    }

    /**
     * The list proper.
     *
     * Accepted items only. A pending suggestion is a message to the owner, not
     * something on their list, and every surface that renders "the list" —
     * shared views, the quiz, Secret Santa, claiming — must not see it.
     *
     * @return HasMany<WishlistItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(WishlistItem::class)->whereNotNull('accepted_at');
    }

    /** Everything, including suggestions awaiting a decision. */
    public function allItems(): HasMany
    {
        return $this->hasMany(WishlistItem::class);
    }

    /** @return HasMany<WishlistItem, $this> */
    public function suggestions(): HasMany
    {
        return $this->hasMany(WishlistItem::class)->whereNull('accepted_at');
    }

    /** @return BelongsTo<Recipient, $this> */
    public function recipient(): BelongsTo
    {
        return $this->belongsTo(Recipient::class);
    }

    /** @return BelongsTo<User, $this> */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    /** @return HasMany<WishlistCollaborator, $this> */
    public function collaborators(): HasMany
    {
        return $this->hasMany(WishlistCollaborator::class);
    }

    public function isForSomeoneElse(): bool
    {
        return $this->kind === ListKind::ForSomeone;
    }

    /**
     * Can anyone holding the link say "I'll get this"?
     *
     * A property of what the list is for, not of how widely it is shared. The
     * model answers it so no surface has to re-decide — and every new lens on a
     * list is a chance to re-decide it wrongly.
     */
    public function allowsClaiming(): bool
    {
        return $this->kind->allowsClaiming();
    }

    /**
     * Claim state must never reach the list owner.
     *
     * The whole value of a gift list is that the owner does not know what has
     * been bought. This is the single rule the wishlist feature exists to
     * protect, so it lives on the model rather than in one controller.
     *
     * Takes an `Owner`, not a `User`, because lists work before signup and the
     * *typical* owner is therefore an anonymous identity. The earlier signature
     * accepted `?User` and answered `false` for null — so an anonymous owner
     * opening their own share link was shown exactly what had been claimed,
     * which is the invariant failing in the ordinary case rather than an
     * exotic one.
     */
    public function shouldHideClaimsFrom(Owner $viewer): bool
    {
        if ($viewer->user !== null) {
            return $this->owner_user_id === $viewer->user->id;
        }

        if ($viewer->anonymous !== null) {
            return $this->owner_anon_id === $viewer->anonymous->getKey();
        }

        return false;
    }
}
