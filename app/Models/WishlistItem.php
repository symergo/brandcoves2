<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\WishlistItemFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WishlistItem extends Model
{
    /** @use HasFactory<WishlistItemFactory> */
    use HasFactory;

    protected $guarded = [];

    /**
     * Never serialise the claim hash. Even though it is one-way, its mere
     * presence tells the list owner that an item has been claimed.
     */
    protected $hidden = ['claimed_by_hash'];

    protected function casts(): array
    {
        return [
            'claimed_at' => 'datetime',
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

    public function isClaimed(): bool
    {
        return $this->claimed_by_hash !== null;
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
