<?php

declare(strict_types=1);

namespace App\Services\Wishlist;

use App\Models\GiftPledge;
use App\Models\Wishlist;
use App\Models\WishlistItem;
use App\Support\Owner;
use Illuminate\Support\Collection;

/**
 * What each person is allowed to know about the money on a list.
 *
 * `GiftPledge`, its controller and both routes shipped complete and were never
 * wired to anything: no page rendered a pledge, and no controller ever loaded
 * the relation, so there was no read path on either side. This is that path,
 * and it exists as one service because the rule it encodes is not uniform —
 * the same question has three different right answers depending on who is
 * asking and what kind of list it is.
 *
 * ## The table this class is
 *
 * | viewer | `mine` list | `group` list |
 * |---|---|---|
 * | the owner | **nothing at all** | total, own share, count, and the breakdown |
 * | anyone else | total, own share, count | total, own share, count |
 *
 * On a `mine` list the owner is the person being surprised, so invariant #4
 * removes them from the table entirely — not a zero, not an empty array, no key
 * at all. A `contributions: null` sitting on every item is a channel that goes
 * live the day somebody tidies the null away, and it would be tidied away by
 * someone who had no idea what it was for.
 *
 * On a `group` list the owner is the organiser, the recipient is a third party
 * who never sees the list, and the breakdown is exactly what the person
 * fronting the money needs. Members still never see each other's amounts,
 * because a public ladder is social pressure on whoever put in least — the
 * count is fine, "who put in what" is not.
 *
 * Amounts are integer cents throughout (invariant #7); the client formats them.
 */
class ContributionView
{
    /**
     * Contribution payloads, keyed by item id.
     *
     * Items with nothing to show are **absent from the array**, so a caller
     * spreading `$contributions[$id] ?? []` into a payload emits no key at all
     * rather than a null that later reads as "nobody has contributed".
     *
     * @param  Collection<int, WishlistItem>  $items  with `pledges` eager-loaded
     * @return array<int, array<string, mixed>>
     */
    public function forItems(Wishlist $list, Collection $items, Owner $viewer, bool $isOwner): array
    {
        if (! $list->kind->allowsContributions()) {
            return [];
        }

        /*
         * A group list pools on the *list*, so it has nothing per item.
         *
         * Without this the page would offer two ways to put money in — a pot in
         * the header and a form under every candidate — and the totals would be
         * two different numbers, both of them true about something.
         * `forList()` is the only contribution path for this kind.
         */
        if ($list->kind->poolsOnTheList()) {
            return [];
        }

        // The owner of a wish list learns nothing here, and the cheapest way to
        // guarantee that is to never build the payload in the first place.
        if ($isOwner && ! $list->kind->ownerSeesContributions()) {
            return [];
        }

        /*
         * Both conditions, not just the kind.
         *
         * `WishlistController::show()` loads through `ListAccess::scope()`, so a
         * *collaborator* on a group list reaches the organiser's page. They are
         * a member of the pool, not the person collecting it, and asking only
         * `ownerSeesContributions()` would hand them the ladder.
         */
        $showsBreakdown = $isOwner && $list->kind->ownerSeesContributions();

        $canContribute = $list->allowsContributionsFrom($viewer);

        $out = [];

        foreach ($items as $item) {
            $pledges = $item->pledges;

            // Nothing pooled and no way to pool anything: there is no sentence
            // to write about this item, so it gets no key.
            if ($pledges->isEmpty() && ! $canContribute) {
                continue;
            }

            $out[$item->id] = [
                'total' => (int) $pledges->sum('amount'),
                'count' => $pledges->count(),
                'mine' => $this->mine($pledges, $viewer),
                ...$showsBreakdown ? ['breakdown' => $this->breakdown($pledges)] : [],
            ];
        }

        return $out;
    }

    /**
     * The pot on a group list: one payload for the whole thing.
     *
     * The per-item version above stays, and is the right shape for a `mine`
     * list — several people chipping in for the one expensive thing on Anna's
     * wishlist is a real act, and it is *about that thing*. A group list is the
     * opposite: it is a shortlist precisely because nobody has decided, so
     * pledging against one candidate asks people to bet, and most of those
     * pledges end up attached to something nobody buys.
     *
     * Same privacy table as `forItems()`, applied once instead of per item —
     * the organiser gets the breakdown, members get the total and their own
     * share. Null rather than an empty shape when there is nothing to say, so
     * the page renders no pot at all rather than an empty one.
     *
     * @return array<string, mixed>|null
     */
    public function forList(Wishlist $list, Owner $viewer, bool $isOwner): ?array
    {
        /*
         * Asked as `poolsOnTheList()`, not as `ownerSeesContributions()`.
         *
         * Those two are the same set today — both mean `Group` — and reusing
         * the second here would read as "the pot exists because the organiser
         * may see it", which is not why. One is about *where the money is
         * attached*, the other about *who may look at it*, and a gate that
         * works because two unrelated questions happen to have the same answer
         * stops working silently the day they diverge. That is exactly how the
         * quiz ended up offerable over a list about somebody else.
         */
        if (! $list->kind->poolsOnTheList()) {
            return null;
        }

        $pledges = $list->pledges;

        if ($pledges->isEmpty() && ! $list->allowsContributionsFrom($viewer)) {
            return null;
        }

        return [
            'total' => (int) $pledges->sum('amount'),
            'count' => $pledges->count(),
            'mine' => $this->mine($pledges, $viewer),
            ...$isOwner ? ['breakdown' => $this->breakdown($pledges)] : [],
        ];
    }

    /**
     * What this viewer has already put in, if anything.
     *
     * Matched on the dual identity rather than on a name, because the name is
     * typed per pledge and an anonymous contributor has nothing else. Null when
     * they have not contributed — distinct from a zero, which would be a
     * promise of nothing rather than the absence of one.
     *
     * @param  Collection<int, GiftPledge>  $pledges
     */
    private function mine(Collection $pledges, Owner $viewer): ?int
    {
        $mine = $pledges->first(function (GiftPledge $pledge) use ($viewer): bool {
            if ($viewer->user !== null) {
                return $pledge->user_id === $viewer->user->id;
            }

            return $viewer->anonymous !== null
                && $pledge->anon_id === $viewer->anonymous->getKey();
        });

        return $mine?->amount;
    }

    /**
     * Who put in what — the organiser's view of a group list, and nobody else's.
     *
     * Names as typed at pledge time. This is the first and only reader of
     * `gift_pledges.display_name`, which has been required on write since the
     * table shipped and read by nothing until now.
     *
     * @param  Collection<int, GiftPledge>  $pledges
     * @return list<array<string, mixed>>
     */
    private function breakdown(Collection $pledges): array
    {
        return $pledges
            ->sortByDesc('amount')
            ->map(fn (GiftPledge $pledge) => [
                'name' => $pledge->display_name,
                'amount' => $pledge->amount,
            ])
            ->values()
            ->all();
    }
}
