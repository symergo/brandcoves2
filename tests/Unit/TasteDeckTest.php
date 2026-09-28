<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Gift\TasteCard;
use App\Services\Gift\TasteChoice;
use App\Services\Gift\TasteDeck;
use App\Services\Gift\TasteProfiler;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Which rounds This or that shows, from a fixed pool: the pure half of
 * TasteDeck. The pool's order stands in for the database's shuffle.
 */
class TasteDeckTest extends TestCase
{
    private const INTERESTS = ['cooking', 'coffee', 'gaming', 'music', 'reading', 'gardening', 'fitness', 'travel', 'art', 'tech'];

    private function deck(): TasteDeck
    {
        return new TasteDeck(new TasteProfiler);
    }

    /**
     * Two cards per interest at each of four price points.
     *
     * @return list<TasteCard>
     */
    private function pool(): array
    {
        $pool = [];
        $id = 0;

        foreach ([1500, 3000, 6000, 12000] as $price) {
            foreach ([1, 2] as $copy) {
                foreach (self::INTERESTS as $interest) {
                    $pool[] = new TasteCard(++$id, ["interest:{$interest}"], [], $price);
                }
            }
        }

        return $pool;
    }

    #[Test]
    public function the_two_sides_of_a_pair_never_share_an_interest(): void
    {
        $rounds = $this->deck()->compose($this->pool(), [], 0, TasteDeck::ROUNDS);

        $this->assertCount(TasteDeck::ROUNDS, $rounds);

        foreach ($rounds as $round) {
            if (count($round) === 2) {
                $this->assertFalse($round[0]->sharesAnInterestWith($round[1]));
            }
        }
    }

    #[Test]
    public function every_round_is_a_pair(): void
    {
        // No like-or-dislike card any more: swiping is a way of its own
        // (owner, 2026-09-28).
        $rounds = $this->deck()->compose($this->pool(), [], 0, TasteDeck::ROUNDS);

        $this->assertCount(TasteDeck::ROUNDS, $rounds);

        foreach ($rounds as $index => $round) {
            $this->assertCount(2, $round, "round {$index}");
        }
    }

    #[Test]
    public function exploring_covers_a_new_interest_on_every_card_and_differs_in_price(): void
    {
        $rounds = $this->deck()->compose($this->pool(), [], 0, 3);

        $seen = [];

        foreach ($rounds as $round) {
            foreach ($round as $card) {
                $this->assertNotContains($card->interests()[0], $seen);
                $seen[] = $card->interests()[0];
            }

            $ratio = max($round[0]->price, $round[1]->price) / min($round[0]->price, $round[1]->price);
            $this->assertGreaterThanOrEqual(1.5, $ratio);
            $this->assertLessThanOrEqual(4.0, $ratio);
        }
    }

    #[Test]
    public function focusing_puts_the_favourite_back_on_the_table_at_a_similar_price(): void
    {
        $choices = [];
        $id = 1000;

        foreach (['gaming', 'music', 'reading'] as $loser) {
            $cooking = new TasteCard(++$id, ['interest:cooking'], [], 3000);
            $choices[] = TasteChoice::pair($cooking, new TasteCard(++$id, ["interest:{$loser}"], [], 3000), $cooking->id);
        }

        $choices[] = TasteChoice::single(new TasteCard(++$id, ['interest:coffee'], [], 3000), TasteChoice::LIKE);

        // Rounds 5 to 7 (indexes 4 to 6) are focusing pairs.
        $rounds = $this->deck()->compose($this->pool(), $choices, 4, 3);

        $withCooking = array_filter($rounds, fn (array $round) => in_array('cooking', array_merge(...array_map(fn (TasteCard $c) => $c->interests(), $round)), true));
        $this->assertNotEmpty($withCooking);

        foreach ($rounds as $round) {
            $ratio = max($round[0]->price, $round[1]->price) / min($round[0]->price, $round[1]->price);
            $this->assertLessThanOrEqual(1.6, $ratio);
        }

        // The second focusing round pits the two favourites against each other.
        $interests = array_merge(...array_map(fn (TasteCard $c) => $c->interests(), $rounds[1]));
        $this->assertEqualsCanonicalizing(['cooking', 'coffee'], $interests);
    }

    #[Test]
    public function a_thin_pool_still_makes_pairs(): void
    {
        $pool = [
            new TasteCard(1, ['interest:cooking'], [], 3000),
            new TasteCard(2, ['interest:cooking'], [], 3000),
            new TasteCard(3, [], [], 3000),
        ];

        $rounds = $this->deck()->compose($pool, [], 0, 2);

        // Untagged cards come last and are still used; the card left over
        // has no partner and is not shown on its own.
        $this->assertCount(1, $rounds);
        $this->assertSame([1, 3], array_map(fn (TasteCard $c) => $c->id, $rounds[0]));
    }

    #[Test]
    public function nothing_is_shown_twice(): void
    {
        $rounds = $this->deck()->compose($this->pool(), [], 0, TasteDeck::ROUNDS);
        $ids = array_merge(...array_map(fn (array $round) => array_map(fn (TasteCard $c) => $c->id, $round), $rounds));

        $this->assertSame($ids, array_values(array_unique($ids)));
    }
}
