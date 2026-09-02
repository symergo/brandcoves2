<?php

declare(strict_types=1);

namespace App\Models;

use App\Services\Wishlist\Board;
use App\Support\Owner;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One message on a list's board.
 *
 * The conversation that decides the buying used to happen in the group chat the
 * link was pasted into — a window with none of the facts in it. This is that
 * conversation beside the list, where what has been claimed, what the pot
 * stands at and what is still unspoken-for are on the same screen.
 *
 * Dual identity like every other row a visitor writes here: `user_id` xor
 * `anon_id`, enforced by a CHECK, because a list works before signup and the
 * typical author is an anonymous cookie identity. `display_name` is typed per
 * message rather than read from the account — the people on a shared list know
 * each other by first name, and half of them have no account to take one from.
 */
class ListMessage extends Model
{
    protected $guarded = [];

    /**
     * Belt and braces beside {@see Board}.
     *
     * Every payload is built field by field there, so these never reach a
     * client by the intended route. This is insurance against the unintended
     * one — a `->toArray()` on a loaded relation — and it matters more here
     * than on most rows: a board is written by the people buying the presents,
     * so `user_id` beside a message naming what somebody has bought is claim
     * state with a name attached to it. The same reasoning as `GiftPledge`.
     *
     * @var list<string>
     */
    protected $hidden = ['user_id', 'anon_id'];

    /**
     * The list this is a message on. Always set.
     *
     * @return BelongsTo<Wishlist, $this>
     */
    public function wishlist(): BelongsTo
    {
        return $this->belongsTo(Wishlist::class);
    }

    /**
     * Did this person write it?
     *
     * Matched on the dual identity rather than on the name, which is typed per
     * message and shared by every "Anna" who ever opens the link. This is what
     * decides whether the delete control is offered — and, at the endpoint,
     * whether it is honoured.
     */
    public function wasWrittenBy(Owner $viewer): bool
    {
        if ($viewer->user !== null) {
            return $this->user_id === $viewer->user->id;
        }

        return $viewer->anonymous !== null
            && $this->anon_id === $viewer->anonymous->getKey();
    }
}
