<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * "I am in for €25."
 *
 * A promise between people, not a payment. The coordination is the hard part of
 * a group gift — who is in, and for how much — and moving the money is a
 * regulated business people are perfectly capable of settling between
 * themselves.
 */
class GiftPledge extends Model
{
    protected $guarded = [];

    /**
     * Belt and braces beside `ContributionView`.
     *
     * Every payload is built field by field there, so these never reach a
     * client by the intended route. This is insurance against the unintended
     * one: a `->toArray()` on a loaded relation, added in a hurry by somebody
     * who did not know that `user_id` next to an amount de-anonymises the whole
     * pool. The same reasoning as `SecretSantaMember::$hidden`.
     *
     * @var list<string>
     */
    protected $hidden = ['user_id', 'anon_id'];

    protected function casts(): array
    {
        return ['amount' => 'integer'];
    }

    /**
     * The list this is money towards. Always set.
     *
     * @return BelongsTo<Wishlist, $this>
     */
    public function wishlist(): BelongsTo
    {
        return $this->belongsTo(Wishlist::class);
    }

    /**
     * The item, when the pledge is against one.
     *
     * Null on a group list, where the whole list is one present and the pot is
     * not attached to any single candidate.
     *
     * @return BelongsTo<WishlistItem, $this>
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(WishlistItem::class, 'item_id');
    }
}
