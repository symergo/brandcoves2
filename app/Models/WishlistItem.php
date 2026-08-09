<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Source;
use Database\Factories\WishlistItemFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WishlistItem extends Model
{
    /** @use HasFactory<WishlistItemFactory> */
    use HasFactory;

    protected $guarded = [];

    /**
     * Never serialise either claim field.
     *
     * `claimed_by_hash` is one-way, but its mere presence tells the list owner
     * that an item has been claimed — and `marked_sent_at` leaks exactly the
     * same secret one step further along. Both belong here for the same reason,
     * and the second was the easy one to forget.
     */
    protected $hidden = ['claimed_by_hash', 'marked_sent_at'];

    protected function casts(): array
    {
        return [
            'claimed_at' => 'datetime',
            'marked_sent_at' => 'datetime',
            'source' => Source::class,
            'accepted_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Wishlist, $this> */
    public function wishlist(): BelongsTo
    {
        return $this->belongsTo(Wishlist::class);
    }

    /** @return BelongsTo<ProductGroup, $this> */
    public function group(): BelongsTo
    {
        return $this->belongsTo(ProductGroup::class, 'group_id');
    }

    /**
     * Who proposed this, when it was somebody other than the owner.
     *
     * @return BelongsTo<User, $this>
     */
    public function suggestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'suggested_by_user_id');
    }

    /** @return HasMany<GiftPledge, $this> */
    public function pledges(): HasMany
    {
        return $this->hasMany(GiftPledge::class, 'item_id');
    }

    /**
     * Must this item's title, image and price be fetched at render time?
     *
     * Amazon may not be mirrored (invariant #6), so its rows deliberately carry
     * no snapshot at all — which puts them in direct tension with the rule that
     * every *other* item snapshots on purpose, so a dropped feed product still
     * shows what the person chose. Both rules are right; the source decides
     * which one applies.
     *
     * A failed fetch hides the item rather than showing stale data.
     */
    public function rendersLive(): bool
    {
        return $this->source !== null && ! $this->source->allowsCatalogueStorage();
    }

    public function isClaimed(): bool
    {
        return $this->claimed_by_hash !== null;
    }

    /**
     * Mark a claimed item as bought and on its way.
     *
     * Restricted to the person holding the claim: "someone bought it" is the
     * claimer's fact to report, and anyone else asserting it would strand a gift
     * that nobody has actually sent.
     */
    public function markSent(string $identityHash): bool
    {
        return static::query()
            ->whereKey($this->getKey())
            ->where('claimed_by_hash', $identityHash)
            ->update(['marked_sent_at' => now(), 'updated_at' => now()]) === 1;
    }

    /**
     * Claim this item, if nobody else already has.
     *
     * Two people opening a shared gift list and tapping "I'll get this" at the
     * same moment is the expected case, not an edge case. A read-then-write
     * would let both succeed and the recipient would get two of the same thing,
     * so the check and the write are one atomic statement and the affected-row
     * count is the answer.
     */
    public function claim(string $identityHash): bool
    {
        $claimed = static::query()
            ->whereKey($this->getKey())
            ->whereNull('claimed_by_hash')
            ->update([
                'claimed_by_hash' => $identityHash,
                'claimed_at' => now(),
                'updated_at' => now(),
            ]);

        if ($claimed === 1) {
            $this->forceFill(['claimed_by_hash' => $identityHash, 'claimed_at' => now()]);

            return true;
        }

        return false;
    }

    /** Only the person who claimed it may release it, and only within the undo window. */
    public function release(string $identityHash): bool
    {
        $undoHours = (int) config('brandcoves.wishlist.claim_undo_hours');

        return static::query()
            ->whereKey($this->getKey())
            ->where('claimed_by_hash', $identityHash)
            ->where('claimed_at', '>=', now()->subHours($undoHours))
            ->update([
                'claimed_by_hash' => null,
                'claimed_at' => null,
                'updated_at' => now(),
            ]) === 1;
    }

    /**
     * One-way identity hash. Uses a dedicated app secret rather than APP_KEY so
     * that rotating APP_KEY does not orphan every existing claim.
     */
    public static function identityHash(string $identity): string
    {
        return hash_hmac('sha256', $identity, (string) config('brandcoves.wishlist.claim_hash_secret'));
    }
}
