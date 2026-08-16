<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Gift\DrawImpossible;
use App\Services\Gift\SantaRepair;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Repairing a draw without re-running it.
 *
 * The assertion that matters most is
 * {@see only_the_one_affected_assignment_changes}. Every other test here can
 * pass while that one fails, and if it fails the feature silently invalidates
 * emails that people are already shopping against — which surfaces as several
 * people buying for the wrong person at the exchange, weeks later.
 *
 * The shuffle is injected throughout, exactly as in `SecretSantaDrawTest`, so
 * the awkward cases are reproducible rather than occasionally red.
 */
class SantaRepairTest extends TestCase
{
    private function repair(): SantaRepair
    {
        return new SantaRepair;
    }

    /** Identity, so a re-drawn subset is deterministic. */
    private function ordered(): callable
    {
        return fn (array $items): array => $items;
    }

    #[Test]
    public function removing_somebody_hands_their_giftee_to_their_giver(): void
    {
        // 1 → 2 → 3 → 1. Remove 2: their giver is 1, their giftee is 3.
        $changed = $this->repair()->remove([1 => 2, 2 => 3, 3 => 1], 2);

        $this->assertSame([1 => 3], $changed);
    }

    #[Test]
    public function only_the_one_affected_assignment_changes(): void
    {
        /*
         * The whole design in one assertion. Everyone is holding an email
         * naming one person; a repair that touches more than it must is a
         * repair that quietly makes those emails wrong.
         */
        $before = [1 => 2, 2 => 3, 3 => 4, 4 => 5, 5 => 1];

        $changed = $this->repair()->remove($before, 3);

        $this->assertCount(1, $changed);
        $this->assertSame([2 => 4], $changed);
    }

    /**
     * Apply a repair and return the arrangement that results.
     *
     * @param  array<int, int>  $before
     * @param  array<int, int>  $changed
     * @return array<int, int>
     */
    private function after(array $before, array $changed, ?int $removed = null): array
    {
        $final = array_replace($before, $changed);

        if ($removed !== null) {
            unset($final[$removed]);
        }

        return $final;
    }

    /**
     * Everybody gives exactly once and receives exactly once, and nobody has
     * themselves. This is what "still a valid draw" means.
     *
     * @param  array<int, int>  $assignments
     */
    private function assertIsAPermutation(array $assignments): void
    {
        $givers = array_keys($assignments);
        $giftees = array_values($assignments);

        $this->assertEqualsCanonicalizing($givers, $giftees, 'somebody gives twice or receives twice');

        foreach ($assignments as $giver => $giftee) {
            $this->assertNotSame($giver, $giftee, "member {$giver} draws themselves");
        }
    }

    #[Test]
    public function an_exclusion_forces_a_redraw_instead_of_the_collapse(): void
    {
        // 1 → 2 → 3 → 4 → 1. Remove 2, so the collapse would give 1 → 3 — but 1
        // excludes 3, so the cycle is re-drawn without 2 instead.
        $before = [1 => 2, 2 => 3, 3 => 4, 4 => 1];

        $changed = $this->repair()->remove($before, 2, [1 => [3]], $this->ordered());

        $final = $this->after($before, $changed, removed: 2);

        $this->assertSame([1, 3, 4], array_keys($final));
        $this->assertNotSame(3, $final[1], 'the exclusion was ignored');
        $this->assertIsAPermutation($final);
    }

    #[Test]
    public function nobody_ever_ends_up_giving_to_themselves(): void
    {
        // 1 → 2 → 3 → 1. Removing 3 leaves a pair, and closing the loop naively
        // would hand 2 to 2.
        $before = [1 => 2, 2 => 3, 3 => 1];

        $changed = $this->repair()->remove($before, 3, [], $this->ordered());

        $this->assertIsAPermutation($this->after($before, $changed, removed: 3));
    }

    #[Test]
    public function the_group_is_still_a_valid_draw_after_a_removal(): void
    {
        $before = [1 => 2, 2 => 3, 3 => 4, 4 => 5, 5 => 1];

        $changed = $this->repair()->remove($before, 4, [], $this->ordered());

        $this->assertIsAPermutation($this->after($before, $changed, removed: 4));
    }

    #[Test]
    public function removing_somebody_from_a_mutual_pair_splices_their_partner_in(): void
    {
        /*
         * Two 2-cycles: (1 2) and (3 4). Remove 2, and 1 loses *both* their
         * giftee and their giver at once — the naive collapse would assign 1 to
         * themselves. 1 has to be spliced into the other cycle instead.
         *
         * Found by a feature test that was flaky precisely because a random
         * four-person draw lands here about a third of the time.
         */
        $before = [1 => 2, 2 => 1, 3 => 4, 4 => 3];

        $changed = $this->repair()->remove($before, 2, [], $this->ordered());

        $final = $this->after($before, $changed, removed: 2);

        $this->assertSame([1, 3, 4], array_keys($final));
        $this->assertIsAPermutation($final);
    }

    #[Test]
    public function a_group_that_would_fall_below_two_is_refused(): void
    {
        // Inherited from `SecretSantaDraw`, so there is one definition of it.
        $this->expectException(DrawImpossible::class);

        $this->repair()->remove([1 => 2, 2 => 1], 1);
    }

    #[Test]
    public function removing_somebody_who_is_not_in_the_group_changes_nothing(): void
    {
        $this->assertSame([], $this->repair()->remove([1 => 2, 2 => 1], 99));
    }

    #[Test]
    public function a_redraw_swaps_exactly_two_givers(): void
    {
        // A transposition, not a re-roll: `santa.redrawn` says "both people have
        // been emailed", and a one-sided change is not a permutation anyway.
        $before = [1 => 2, 2 => 3, 3 => 4, 4 => 1];

        $changed = $this->repair()->redraw($before, 1, [], $this->ordered());

        $this->assertCount(2, $changed);

        $this->assertIsAPermutation($this->after($before, $changed));
    }

    #[Test]
    public function a_redraw_gives_the_member_somebody_new(): void
    {
        $before = [1 => 2, 2 => 3, 3 => 4, 4 => 1];

        $changed = $this->repair()->redraw($before, 1, [], $this->ordered());
        $final = array_replace($before, $changed);

        $this->assertNotSame(2, $final[1]);
        $this->assertNotSame(1, $final[1]);
    }

    #[Test]
    public function a_redraw_respects_both_sides_exclusions(): void
    {
        $before = [1 => 2, 2 => 3, 3 => 4, 4 => 1];

        // 1 may not have 3 (so swapping with 2 is out) and 4 may not have 2
        // (so swapping with 4 is out). Only the swap with 3 remains.
        $changed = $this->repair()->redraw($before, 1, [1 => [3], 4 => [2]], $this->ordered());

        $final = array_replace($before, $changed);

        $this->assertSame(4, $final[1]);
        $this->assertSame(2, $final[3]);
    }

    #[Test]
    public function a_redraw_with_no_legal_swap_names_the_member(): void
    {
        // Two people can only ever have each other.
        try {
            $this->repair()->redraw([1 => 2, 2 => 1], 1, [], $this->ordered());
            $this->fail('expected DrawImpossible');
        } catch (DrawImpossible $e) {
            $this->assertSame(1, $e->blockedBy);
        }
    }
}
