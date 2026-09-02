<?php

declare(strict_types=1);

namespace App\Services\Wishlist;

use App\Models\GiftPledge;
use App\Models\Wishlist;
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
 * | viewer | a `group` list |
 * |---|---|
 * | the organiser | total, own share, count, and the breakdown |
 * | anyone else | total, own share, count — plus the names, if the organiser said so |
 *
 * Only a group list has a pot at all. The owner there is the organiser and the
 * recipient is a third party who never sees the list, so the breakdown is
 * exactly what the person fronting the money needs — and members still never
 * see each other's **amounts**, because a public ladder is social pressure on
 * whoever put in least. That half is a rule and cannot be switched on.
 *
 * The *names* are a setting, `wishlists.pledgers_visible`, off until asked. The
 * count alone was the only answer and was therefore acting as a rule, which it
 * is not: six colleagues buying a leaving present mostly want to know whether
 * the other five are in, and a pot that will not say is a pot somebody chases
 * by message. So "who is in" may be shared; "who put in what" still may not.
 *
 * Null rather than an empty shape when there is nothing to say, so a page
 * renders no pot rather than an empty one.
 *
 * Amounts are integer cents throughout (invariant #7); the client formats them.
 */
class ContributionView
{
    /**
     * The pot: one payload for the whole present.
     *
     * There was a per-item version beside this, for a `mine` list where several
     * people might go in on one expensive thing. It is gone — rendered, it put
     * an "I'm in" under every card on a six-item list, beside the claim button
     * that is the actual action there. On a wish list you claim a thing; going
     * in together on one *is* a group gift, and a group list is what that is.
     *
     * The organiser gets the breakdown, members get the total and their own
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
        if (! $list->kind->allowsContributions()) {
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
            /*
             * What one person puts in, when the organiser has said everybody
             * puts in the same. Cents, or null for "everyone names their own".
             *
             * Sent to the members, not only to the organiser, and it has to be:
             * the endpoint ignores whatever amount they post once this is set,
             * so a form that still asked for one would take a number, thank
             * them for it, and store something else.
             */
            'standard' => $list->standardPledge(),
            ...$isOwner ? ['breakdown' => $this->breakdown($pledges)] : [],
            /*
             * Who is in, when the organiser has said everyone may know.
             *
             * Names and nothing else. The count alone was the only setting and
             * therefore a rule, and it is not one: six colleagues buying a
             * leaving present mostly want to know whether the other five are
             * actually in, and a pot that will not say is a pot somebody chases
             * by message.
             *
             * Amounts are still not here and cannot be turned on, because that
             * half of the rule is not a preference — a visible ladder is social
             * pressure on whoever put in least. The organiser's `breakdown`
             * above is the one place a number is attached to a name.
             *
             * Sent to the organiser too, and deliberately: it is what the
             * others are looking at, and a setting whose effect the person who
             * set it cannot see is a setting they have to take on trust.
             */
            ...$list->pledgersVisible() ? ['names' => $this->names($pledges)] : [],
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
    /**
     * Who is in — names as typed at pledge time, in the order they joined.
     *
     * Not sorted by amount, unlike {@see breakdown()}. Ordering a list of names
     * by money is the ladder this deliberately does not show, rebuilt out of
     * the sequence: the top name would be the biggest contributor and everybody
     * would read it that way. Joining order says nothing about anybody.
     *
     * @param  Collection<int, GiftPledge>  $pledges
     * @return list<string>
     */
    private function names(Collection $pledges): array
    {
        return $pledges
            ->sortBy('id')
            ->map(fn (GiftPledge $pledge): string => (string) $pledge->display_name)
            ->values()
            ->all();
    }

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
