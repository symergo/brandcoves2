<?php

declare(strict_types=1);

namespace App\Services\Social;

use App\Enums\ListKind;
use App\Enums\ListVisibility;
use App\Models\User;
use App\Models\UserBlock;
use App\Models\UserFollow;
use App\Models\Wishlist;
use Illuminate\Support\Collection;

/**
 * Who can see whose lists.
 *
 * The graph is built from links people actually send each other, not imported
 * from a social network — see the migration for why importing a friend list is
 * neither available nor advisable.
 */
class FollowGraph
{
    public function follow(User $follower, User $followed, string $source = 'manual'): void
    {
        if ($follower->id === $followed->id || $this->eitherBlocks($follower, $followed)) {
            return;
        }

        UserFollow::updateOrCreate(
            ['follower_id' => $follower->id, 'followed_id' => $followed->id],
            ['source' => $source],
        );
    }

    public function unfollow(User $follower, User $followed): void
    {
        UserFollow::query()
            ->where('follower_id', $follower->id)
            ->where('followed_id', $followed->id)
            ->delete();
    }

    /**
     * Block, and sever the relationship in both directions.
     *
     * A block that leaves the existing follow in place is a block that does not
     * work: the person you blocked keeps seeing your lists, which is precisely
     * the thing you pressed the button to stop.
     */
    public function block(User $blocker, User $blocked): void
    {
        if ($blocker->id === $blocked->id) {
            return;
        }

        UserBlock::updateOrCreate([
            'blocker_id' => $blocker->id,
            'blocked_id' => $blocked->id,
        ]);

        UserFollow::query()
            ->where(fn ($q) => $q
                ->where(fn ($p) => $p->where('follower_id', $blocker->id)->where('followed_id', $blocked->id))
                ->orWhere(fn ($p) => $p->where('follower_id', $blocked->id)->where('followed_id', $blocker->id)))
            ->delete();
    }

    public function eitherBlocks(User $a, User $b): bool
    {
        return UserBlock::query()
            ->where(fn ($q) => $q
                ->where(fn ($p) => $p->where('blocker_id', $a->id)->where('blocked_id', $b->id))
                ->orWhere(fn ($p) => $p->where('blocker_id', $b->id)->where('blocked_id', $a->id)))
            ->exists();
    }

    /**
     * The lists of people you follow.
     *
     * Two restrictions that are the whole point of this method:
     *
     * 1. **Only `mine` lists.** Never someone's `for_someone` research, which is
     *    private notes about a third party who is not part of this relationship
     *    at all. Following somebody is not consent to read what they are
     *    plotting for their sister.
     * 2. **Only `public` lists.** A `link` list is shared with whoever holds the
     *    URL; putting it in a feed hands it to an audience the owner never chose.
     *
     * @return Collection<int, Wishlist>
     */
    public function friendsLists(User $viewer): Collection
    {
        $blocked = UserBlock::query()
            ->where('blocker_id', $viewer->id)->pluck('blocked_id')
            ->merge(UserBlock::query()->where('blocked_id', $viewer->id)->pluck('blocker_id'));

        $following = UserFollow::query()
            ->where('follower_id', $viewer->id)
            ->pluck('followed_id')
            ->reject(fn (int $id) => $blocked->contains($id));

        if ($following->isEmpty()) {
            return new Collection;
        }

        return Wishlist::query()
            ->whereIn('owner_user_id', $following)
            ->where('kind', ListKind::Mine->value)
            ->where('visibility', ListVisibility::Public->value)
            ->with('owner')
            ->withCount('items')
            ->latest('updated_at')
            ->limit(50)
            ->get();
    }
}
