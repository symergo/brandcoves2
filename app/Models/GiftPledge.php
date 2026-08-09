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

    protected function casts(): array
    {
        return ['amount' => 'integer'];
    }

    /** @return BelongsTo<WishlistItem, $this> */
    public function item(): BelongsTo
    {
        return $this->belongsTo(WishlistItem::class, 'item_id');
    }
}
