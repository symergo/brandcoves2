<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Gift\DrawImpossible;
use App\Services\Gift\SecretSantaDraw;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The draw.
 *
 * The load-bearing assertion is {@see it_solves_a_set_a_retry_loop_would_give_up_on}.
 * A shuffle-and-retry draw passes every other test in this file and fails that
 * one roughly half the time — which is exactly the shape of bug that reaches
 * production, because it only appears once a real group is awkward enough.
 */
class SecretSantaDrawTest extends TestCase
{
    private function draw(): SecretSantaDraw
    {
        return new SecretSantaDraw;
    }

    /** Deterministic order, so a passing test is a proof and not a coin toss. */
    private function ordered(): callable
    {
        return static fn (array $candidates): array => $candidates;
    }

    #[Test]
    public function nobody_is_ever_assigned_to_themselves(): void
    {
        $assignments = $this->draw()->assign(['a', 'b', 'c', 'd', 'e']);

        $this->assertCount(5, $assignments);

        foreach ($assignments as $giver => $giftee) {
            $this->assertNotSame($giver, $giftee);
        }
    }

    #[Test]
    public function everyone_gives_once_and_receives_once(): void
    {
        $assignments = $this->draw()->assign(['a', 'b', 'c', 'd', 'e', 'f']);

        // A giftee drawn twice means somebody gets two presents and somebody
        // gets none, which is the failure nobody notices until the day.
        $this->assertSame(
            count($assignments),
            count(array_unique(array_values($assignments))),
        );
    }

    #[Test]
    public function a_draw_respects_exclusions(): void
    {
        $assignments = $this->draw()->assign(
            ['a', 'b', 'c', 'd'],
            ['a' => ['b'], 'b' => ['a']],
        );

        // Couples excluding each other is the ordinary case, not an exotic one.
        $this->assertNotSame('b', $assignments['a']);
        $this->assertNotSame('a', $assignments['b']);
    }

    #[Test]
    public function it_solves_a_set_a_retry_loop_would_give_up_on(): void
    {
        /*
         * THE TEST THIS CLASS EXISTS FOR.
         *
         * Ten members, each of whom may give to exactly one other person, so the
         * constraints admit precisely one arrangement: the cycle.
         *
         * There are 1,334,961 derangements of ten items. A shuffle-and-retry
         * draw is picking one of those at random and checking it, so a cap of
         * 200 attempts has about a one in six thousand chance of ever
         * succeeding — on an input with a perfectly good answer. Matching finds
         * it on the first pass, every time.
         */
        $members = ['a', 'b', 'c', 'd', 'e', 'f', 'g', 'h', 'i', 'j'];
        $exclusions = [];

        foreach ($members as $index => $giver) {
            $onlyAllowed = $members[($index + 1) % count($members)];

            $exclusions[$giver] = array_values(array_filter(
                $members,
                fn (string $other) => $other !== $onlyAllowed,
            ));
        }

        $assignments = $this->draw()->assign($members, $exclusions, $this->ordered());

        $this->assertCount(10, $assignments);

        foreach ($members as $index => $giver) {
            $this->assertSame($members[($index + 1) % 10], $assignments[$giver]);
        }
    }

    #[Test]
    public function two_couples_who_exclude_each_other_can_still_be_drawn(): void
    {
        // The ordinary awkward case, and the one every real group has.
        $assignments = $this->draw()->assign(
            ['ann', 'bob', 'cid', 'dee'],
            ['ann' => ['bob'], 'bob' => ['ann'], 'cid' => ['dee'], 'dee' => ['cid']],
        );

        $this->assertCount(4, $assignments);
        $this->assertSame(4, count(array_unique(array_values($assignments))));
        $this->assertNotSame('bob', $assignments['ann']);
        $this->assertNotSame('ann', $assignments['bob']);
        $this->assertNotSame('dee', $assignments['cid']);
        $this->assertNotSame('cid', $assignments['dee']);
    }

    #[Test]
    public function an_impossible_set_fails_loudly_and_names_the_blocker(): void
    {
        try {
            // Sam can draw nobody at all.
            $this->draw()->assign(
                ['a', 'b', 'sam'],
                ['sam' => ['a', 'b']],
            );

            $this->fail('Expected DrawImpossible');
        } catch (DrawImpossible $e) {
            // "Could not draw after 200 tries" leaves the organiser with nothing
            // to do. Naming the member turns it into a conversation.
            $this->assertSame('sam', $e->blockedBy);
        }
    }

    #[Test]
    public function a_group_of_one_cannot_be_drawn(): void
    {
        $this->expectException(DrawImpossible::class);

        $this->draw()->assign(['a']);
    }

    #[Test]
    public function two_people_simply_swap(): void
    {
        $assignments = $this->draw()->assign(['a', 'b']);

        $this->assertSame(['a' => 'b', 'b' => 'a'], $assignments);
    }

    #[Test]
    public function repeated_draws_are_not_all_identical(): void
    {
        $seen = [];

        for ($i = 0; $i < 40; $i++) {
            $seen[] = json_encode($this->draw()->assign(['a', 'b', 'c', 'd', 'e']));
        }

        // Randomness lives in the candidate shuffle rather than in retrying, so
        // it has to be checked separately — a matcher that always returns the
        // same arrangement would pass every other test here and make the same
        // pairs every year.
        $this->assertGreaterThan(1, count(array_unique($seen)));
    }
}
