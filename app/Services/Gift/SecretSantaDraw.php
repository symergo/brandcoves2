<?php

declare(strict_types=1);

namespace App\Services\Gift;

/**
 * Who buys for whom.
 *
 * Pure: ids and exclusions in, an assignment map out. No database, no network,
 * no randomness the caller cannot control — so the awkward cases can be tested
 * exactly rather than approximately.
 *
 * ## Why this is a matching problem and not a shuffle
 *
 * The obvious implementation shuffles the members and pairs each with the next,
 * retrying until nothing violates an exclusion. v1 did that, with a cap of 200
 * attempts, and it has two failure modes that only appear once a group is
 * awkward enough to care:
 *
 * 1. **It fails on solvable inputs.** Four people where three all exclude the
 *    same person has exactly one valid arrangement; a random shuffle finds it
 *    about 4% of the time, so 200 attempts is a coin toss that the organiser
 *    experiences as "the button doesn't work".
 * 2. **It cannot say why.** "Could not draw after 200 tries" is indistinguishable
 *    from a genuinely impossible constraint set, so nobody knows whether to
 *    retry or to go and talk to their brother about his exclusion list.
 *
 * Treating it as a **bipartite perfect matching** — givers on one side,
 * receivers on the other, an edge wherever a pairing is allowed — settles both.
 * Augmenting-path search finds a matching whenever one exists, and when it fails
 * it fails on a specific person, who is the useful thing to name.
 *
 * Randomness comes from shuffling each giver's candidate list rather than from
 * retrying, so the result is still a different draw every year without the
 * algorithm's success depending on luck.
 */
final class SecretSantaDraw
{
    /**
     * @param  list<int|string>  $members
     * @param  array<int|string, list<int|string>>  $exclusions  giver => people they must not draw
     * @param  (callable(list<int|string>): list<int|string>)|null  $shuffle  injectable for deterministic tests
     * @return array<int|string, int|string> giver => giftee
     *
     * @throws DrawImpossible
     */
    public function assign(array $members, array $exclusions = [], ?callable $shuffle = null): array
    {
        if (count($members) < 2) {
            // One person cannot draw anybody but themselves, which is the one
            // pairing that is never allowed.
            throw new DrawImpossible(__('site.santa.too_few'), null);
        }

        $shuffle ??= static function (array $candidates): array {
            shuffle($candidates);

            return $candidates;
        };

        $allowed = [];

        foreach ($members as $giver) {
            $blocked = $exclusions[$giver] ?? [];

            $candidates = array_values(array_filter(
                $members,
                // Nobody draws themselves. That is the definition of the game,
                // not a configurable exclusion.
                fn ($receiver) => $receiver !== $giver && ! in_array($receiver, $blocked, true),
            ));

            if ($candidates === []) {
                throw new DrawImpossible(__('site.santa.impossible'), $giver);
            }

            $allowed[$giver] = $shuffle($candidates);
        }

        /*
         * Givers with the fewest options first.
         *
         * Not required for correctness — augmenting paths handle any order — but
         * it dramatically reduces the reshuffling, and it means the member who
         * causes an impossible set is usually the one the failure names.
         */
        uasort($allowed, fn (array $a, array $b) => count($a) <=> count($b));

        /** @var array<int|string, int|string> $takenBy receiver => giver */
        $takenBy = [];

        foreach (array_keys($allowed) as $giver) {
            if (! $this->match($giver, $allowed, $takenBy, [])) {
                throw new DrawImpossible(__('site.santa.impossible'), $giver);
            }
        }

        $assignments = [];

        foreach ($takenBy as $receiver => $giver) {
            $assignments[$giver] = $receiver;
        }

        return $assignments;
    }

    /**
     * Find a receiver for this giver, displacing others if that is what it takes.
     *
     * The augmenting step: if every candidate is taken, ask each holder to move
     * somewhere else. `$seen` stops the search revisiting a receiver inside one
     * augmentation, which is what makes it terminate.
     *
     * @param  array<int|string, list<int|string>>  $allowed
     * @param  array<int|string, int|string>  $takenBy
     * @param  list<int|string>  $seen
     */
    private function match(int|string $giver, array $allowed, array &$takenBy, array $seen): bool
    {
        foreach ($allowed[$giver] as $receiver) {
            if (in_array($receiver, $seen, true)) {
                continue;
            }

            $seen[] = $receiver;

            $holder = $takenBy[$receiver] ?? null;

            if ($holder === null || $this->match($holder, $allowed, $takenBy, $seen)) {
                $takenBy[$receiver] = $giver;

                return true;
            }
        }

        return false;
    }
}
