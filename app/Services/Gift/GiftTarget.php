<?php

declare(strict_types=1);

namespace App\Services\Gift;

use App\Enums\ListKind;
use App\Enums\ListVisibility;
use App\Enums\Market;
use App\Models\Recipient;
use App\Models\User;
use App\Models\Wishlist;
use Illuminate\Support\Collection;

/**
 * Who you are shopping for.
 *
 * Three different features answer that question from three different places —
 * a saved recipient, a Secret Santa assignment, or nobody at all when you are
 * building your own list. Without this seam each of them would grow its own
 * copy of "find their list, find their brief, show me both", and the second
 * copy is where the claim-privacy rule gets forgotten.
 *
 * Deliberately *not* built by minting a recipient row per Secret Santa giver:
 * that would write the pairing into `recipients.name` in plain text and make
 * the encrypted assignment column decorative.
 */
final readonly class GiftTarget
{
    /**
     * @param  string  $name  what to call them on screen
     * @param  User|null  $person  their account, when one is known
     * @param  Recipient|null  $recipient  the giver's own notes about them
     */
    private function __construct(
        public string $name,
        public Market $market,
        public ?User $person = null,
        public ?Recipient $recipient = null,
    ) {}

    public static function fromRecipient(Recipient $recipient, Market $market): self
    {
        return new self(
            name: $recipient->name,
            market: $market,
            person: $recipient->isLinked() ? $recipient->person : null,
            recipient: $recipient,
        );
    }

    /**
     * A drawn Secret Santa giftee.
     *
     * The display name comes from the membership, not from a recipient row,
     * because no recipient row exists — the pairing lives in one encrypted
     * column and nowhere else.
     */
    public static function fromPerson(string $name, Market $market, ?User $person = null): self
    {
        return new self(name: $name, market: $market, person: $person);
    }

    /** Building your own list: the target is you. */
    public static function myself(Market $market, ?User $person = null): self
    {
        return new self(name: __('site.gift.myself'), market: $market, person: $person);
    }

    public function isLinked(): bool
    {
        return $this->person !== null;
    }

    /**
     * What they have actually asked for.
     *
     * Only lists they chose to share: an account being linked is permission to
     * be *found*, not permission to read everything they own. A private list
     * stays private to the person who made it, exactly as it would to anyone
     * else holding a link to it.
     *
     * @return Collection<int, Wishlist>
     */
    public function statedWishes(): Collection
    {
        if ($this->person === null) {
            return new Collection;
        }

        return Wishlist::query()
            ->where('owner_user_id', $this->person->id)
            // Their own lists, never their research about a third party.
            ->whereIn('kind', [ListKind::Mine->value])
            ->where('visibility', '!=', ListVisibility::Private->value)
            ->with(['items.group'])
            ->latest('updated_at')
            ->get();
    }

    /**
     * Their wish lists that this giver may pick from.
     *
     * The list about a friend (docs/features/wish-list-for-my-people.md). Not
     * {@see statedWishes()}, which reads every link-shared wish list of theirs:
     * being linked to a person is permission to be found, and a wish list
     * reaches a giver here only by something its owner did for that giver.
     *
     * - "Visible to my people" is on and the two are friends; or
     * - it is shared by link and was shared with this giver by name, or this
     *   giver opened its link (the two ways it reaches their My people row).
     *
     * Their wish lists only, never their research about somebody else. Items
     * are loaded; claim state on them is for the giver, who is a visitor to
     * this list, and never reaches its owner (invariant 4).
     *
     * @return Collection<int, Wishlist>
     */
    public function wishesSeenBy(?User $viewer): Collection
    {
        if ($this->person === null || $viewer === null || $viewer->id === $this->person->id) {
            return new Collection;
        }

        return Wishlist::query()
            ->where('owner_user_id', $this->person->id)
            ->where('kind', ListKind::Mine->value)
            ->where(fn ($either) => $either
                ->where(fn ($shown) => $shown->visibleToFriend($viewer))
                ->orWhere(fn ($given) => $given
                    ->where('visibility', '!=', ListVisibility::Private->value)
                    ->where(fn ($q) => $q
                        ->whereHas('shares', fn ($share) => $share->where('user_id', $viewer->id))
                        ->orWhereHas('opens', fn ($open) => $open->where('user_id', $viewer->id)))))
            ->with(['items.group'])
            ->latest('updated_at')
            ->get();
    }

    /**
     * The brief to rank suggestions against.
     *
     * Null when nobody has described them: the engine falls back to a budget
     * browse rather than pretending to know something.
     */
    public function brief(int $limit = 4): ?TasteBrief
    {
        return $this->recipient === null
            ? null
            : TasteBrief::fromRecipient($this->recipient, $this->market, $limit);
    }
}
