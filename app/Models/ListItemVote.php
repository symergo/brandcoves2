<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * "This is the one we should get."
 *
 * Approval voting on the candidates of a group list: any member may back any
 * number of them, one row per person per item, and pressing again takes it
 * back. The tally is the shortlist's order.
 *
 * A sibling of {@see GiftPledge} on purpose — same dual identity, same partial
 * uniques — because it answers the other half of the same question. The pledge
 * says how much is available; this says what to spend it on.
 */
class ListItemVote extends Model
{
    protected $guarded = [];

    /**
     * Belt and braces, as on `GiftPledge`.
     *
     * A tally is public and a voter is not: "four people want the espresso
     * machine" is the useful fact, and "Bob wanted the espresso machine" is a
     * disagreement waiting to happen inside a group buying somebody a present.
     * Nothing builds a payload from these columns, and this is insurance
     * against the unintended route — a `->toArray()` on a loaded relation.
     *
     * @var list<string>
     */
    protected $hidden = ['user_id', 'anon_id'];

    /** @return BelongsTo<WishlistItem, $this> */
    public function item(): BelongsTo
    {
        return $this->belongsTo(WishlistItem::class, 'item_id');
    }
}
