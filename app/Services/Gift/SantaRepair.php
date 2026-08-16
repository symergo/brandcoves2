<?php

declare(strict_types=1);

namespace App\Services\Gift;

/**
 * Repairing a draw that has already happened.
 *
 * ## Why not simply draw again
 *
 * Every member is holding an email naming one person, and a sent email cannot
 * be unsent. Most people never open the group page again after that mail. A
 * full re-draw silently invalidates all of them, and the failure mode is not an
 * error message — it is several people buying for the wrong person and finding
 * out at the exchange.
 *
 * So a repair changes **as few assignments as it can** and the caller emails
 * exactly the people whose assignment moved. Both methods return only the
 * changed pairs for that reason: a caller cannot accidentally rewrite the
 * untouched members and re-mail the lot.
 *
 * ## Pure, like {@see SecretSantaDraw} beside it
 *
 * Ids in, ids out, no database and no models. The awkward cases here are
 * combinatorial rather than relational, and they are only testable in a
 * satisfying way if they can be set up as five integers and a map.
 */
class SantaRepair
{
    public function __construct(private readonly SecretSantaDraw $draw = new SecretSantaDraw) {}

    /**
     * Somebody drops out. Their giver takes on their giftee.
     *
     * An assignment set is a permutation. Removing member **X** leaves a hole
     * between their giver **G** and their giftee **T**, and closing it is one
     * write: `G → T`. Only G is affected, and only G is emailed — T's *giver*
     * changed, and T never knew who that was, so there is nothing to tell them.
     *
     * Three cases need naming:
     *
     * - **G excludes T.** The collapse is illegal, so the whole cycle X sat in
     *   is re-drawn without them. A cycle's givers and its receivers are the
     *   same set, which is exactly the shape {@see SecretSantaDraw::assign()}
     *   already takes.
     * - **G === T** — X sat in a mutual pair, so closing the loop would assign
     *   somebody to themselves. Whatever remains is re-drawn instead.
     * - **Fewer than two would remain.** `assign()` already refuses that, so
     *   the refusal is inherited rather than reimplemented here.
     *
     * @param  array<int, int>  $assignments  giver => giftee, the whole group
     * @param  int  $leaving  the member being removed
     * @param  array<int, list<int>>  $exclusions  giver => members they may not draw
     * @param  callable|null  $shuffle  injected for deterministic tests
     * @return array<int, int> only the assignments that changed
     *
     * @throws DrawImpossible
     */
    public function remove(array $assignments, int $leaving, array $exclusions = [], ?callable $shuffle = null): array
    {
        if (! array_key_exists($leaving, $assignments)) {
            return [];
        }

        $remaining = array_values(array_diff(array_keys($assignments), [$leaving]));

        if (count($remaining) < 2) {
            // Inherited, so there is one definition of "too few to draw".
            throw new DrawImpossible(__('site.santa.too_few'), null);
        }

        $giftee = $assignments[$leaving];
        $giver = $this->giverOf($assignments, $leaving);

        // Nobody was assigned to them — an inconsistent set rather than a
        // arrangement we can repair. Re-draw what is left.
        if ($giver === null) {
            return $this->redrawEveryone($remaining, $exclusions, $shuffle);
        }

        $blocked = $exclusions[$giver] ?? [];

        // The ordinary case: one write, one email.
        if ($giver !== $giftee && ! in_array($giftee, $blocked, true)) {
            return [$giver => $giftee];
        }

        /*
         * The collapse is illegal. Re-draw the cycle X was in rather than the
         * whole group — everybody outside that cycle keeps the person they were
         * already told about, which is the point of the exercise.
         */
        $cycle = array_values(array_diff($this->cycleContaining($assignments, $leaving), [$leaving]));

        $subset = count($cycle) >= 2 ? $cycle : $remaining;

        return $this->redrawEveryone($subset, $exclusions, $shuffle, $assignments);
    }

    /**
     * "Not this person" — swap two givers' giftees.
     *
     * A **transposition**, not a re-roll, which is what the copy already
     * committed to: `santa.redrawn` reads "Redrawn. Both people have been
     * emailed." Exactly two givers change and exactly two are emailed, and
     * neither learns anything about the other.
     *
     * A re-roll of one person alone is not possible anyway — giving M somebody
     * else's giftee leaves that giftee with two givers and somebody with none.
     *
     * @param  array<int, int>  $assignments  giver => giftee
     * @param  int  $member  the giver who wants a different giftee
     * @param  array<int, list<int>>  $exclusions
     * @return array<int, int> the two changed assignments
     *
     * @throws DrawImpossible
     */
    public function redraw(array $assignments, int $member, array $exclusions = [], ?callable $shuffle = null): array
    {
        if (! array_key_exists($member, $assignments)) {
            return [];
        }

        $shuffle ??= function (array $items): array {
            shuffle($items);

            return $items;
        };

        $mine = $assignments[$member];

        $candidates = $shuffle(array_values(array_filter(
            array_keys($assignments),
            fn (int $other) => $other !== $member,
        )));

        foreach ($candidates as $other) {
            $theirs = $assignments[$other];

            // The swap: I take theirs, they take mine. Neither may end up with
            // themselves, and neither may take somebody they excluded.
            if ($theirs === $member || $mine === $other) {
                continue;
            }

            if (in_array($theirs, $exclusions[$member] ?? [], true)) {
                continue;
            }

            if (in_array($mine, $exclusions[$other] ?? [], true)) {
                continue;
            }

            return [$member => $theirs, $other => $mine];
        }

        // Every swap would break a rule. Naming the member is what lets the
        // organiser see whose exclusions are the problem.
        throw new DrawImpossible(__('site.santa.impossible'), $member);
    }

    /**
     * Re-draw a subset from scratch, returning only what actually moved.
     *
     * @param  list<int>  $members
     * @param  array<int, list<int>>  $exclusions
     * @param  array<int, int>  $before  to diff against; empty means "everything is a change"
     * @return array<int, int>
     */
    private function redrawEveryone(array $members, array $exclusions, ?callable $shuffle, array $before = []): array
    {
        $fresh = $this->draw->assign(
            $members,
            // Only the exclusions belonging to the people being re-drawn, and
            // only naming people still in the subset.
            array_map(
                fn (array $blocked) => array_values(array_intersect($blocked, $members)),
                array_intersect_key($exclusions, array_flip($members)),
            ),
            $shuffle,
        );

        return array_filter(
            $fresh,
            fn (int $giftee, int $giver) => ($before[$giver] ?? null) !== $giftee,
            ARRAY_FILTER_USE_BOTH,
        );
    }

    /** Who is assigned to this member? @param array<int, int> $assignments */
    private function giverOf(array $assignments, int $giftee): ?int
    {
        foreach ($assignments as $giver => $theirs) {
            if ($theirs === $giftee) {
                return $giver;
            }
        }

        return null;
    }

    /**
     * The cycle one member sits in.
     *
     * A permutation is a set of disjoint cycles, and a removal only ever
     * disturbs the one containing the leaver. Walking it is what lets the
     * exclusion fallback re-draw six people instead of sixty.
     *
     * @param  array<int, int>  $assignments
     * @return list<int>
     */
    private function cycleContaining(array $assignments, int $start): array
    {
        $cycle = [];
        $at = $start;

        // Bounded by the size of the map: a malformed set cannot loop forever.
        while (! in_array($at, $cycle, true) && array_key_exists($at, $assignments)) {
            $cycle[] = $at;
            $at = $assignments[$at];
        }

        return $cycle;
    }
}
