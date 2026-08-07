<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ListVisibility;
use App\Enums\Market;
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
            'is_gift_list' => 'boolean',
        ];
    }

    /** @return HasMany<WishlistItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(WishlistItem::class);
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
        return $this->recipient_id !== null;
    }

    /**
     * Claim state must never reach the list owner.
     *
     * The whole value of a gift list is that the owner does not know what has
     * been bought. This is the single rule the wishlist feature exists to
     * protect, so it lives on the model rather than in one controller.
     */
    public function shouldHideClaimsFrom(?User $viewer): bool
    {
        if ($viewer === null) {
            return false;
        }

        return $this->owner_user_id === $viewer->id;
    }
}
