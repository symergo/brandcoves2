<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Gift\DeckSeed;
use App\Services\Gift\SwipeDeck;
use App\Services\Gift\TasteCard;
use App\Services\Gift\TasteDeck;
use App\Services\Gift\TasteProfiler;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Which cards Swipe gifts shows next, from a fixed pool: the pure half of
 * SwipeDeck. See docs/features/swipe-gifts.md.
 */
class SwipeDeckTest extends TestCase
{
    private const INTERESTS = ['cooking', 'coffee', 'gaming', 'music', 'reading', 'gardening', 'fitness', 'travel', 'art', 'tech'];

    private function deck(): SwipeDeck
    {
        return new SwipeDeck(new TasteDeck(new TasteProfiler));
    }

    /** @return list<TasteCard> */
    private function pool(): array
    {
        $pool = [];
        $id = 0;

        foreach ([1500, 3000, 6000, 12000] as $price) {
            foreach (self::INTERESTS as $interest) {
                $pool[] = new TasteCard(++$id, ["interest:{$interest}"], [], $price);
            }
        }

        return $pool;
    }

    private function card(string $interest, int $price = 3000): TasteCard
    {
        static $id = 1000;

        return new TasteCard(++$id, ["interest:{$interest}"], [], $price);
    }

    /** @param  list<TasteCard>  $cards @return list<string> */
    private function interests(array $cards): array
    {
        return array_map(fn (TasteCard $c) => $c->interests()[0], $cards);
    }

    #[Test]
    public function before_anything_is_liked_every_card_shows_another_interest(): void
    {
        $cards = $this->deck()->compose($this->pool(), [], [], SwipeDeck::BATCH);

        $this->assertCount(SwipeDeck::BATCH, $cards);
        $this->assertSame($this->interests($cards), array_values(array_unique($this->interests($cards))));
    }

    #[Test]
    public function a_liked_perfume_is_one_favourite_not_two(): void
    {
        // A perfume carries perfume and beauty. Counted twice it made two
        // favourites, and the deck filled with perfume and beauty (2026-09-30).
        $perfume = new TasteCard(900, ['interest:perfume', 'interest:beauty'], [], 6000);

        $this->assertSame(['perfume'], $perfume->interests());
        $this->assertSame(['perfume' => 1.0], SwipeDeck::scores([$perfume], []));

        // Beauty on its own is still beauty.
        $this->assertSame(['beauty'], (new TasteCard(901, ['interest:beauty'], [], 3000))->interests());
    }

    #[Test]
    public function a_liked_interest_comes_back_two_cards_in_three(): void
    {
        $cards = $this->deck()->compose($this->pool(), [$this->card('coffee')], [], 6);

        // Positions 0, 1, 3 and 4 follow the favourite; 2 and 5 explore.
        $this->assertSame(['coffee', 'coffee'], [$cards[0]->interests()[0], $cards[1]->interests()[0]]);
        $this->assertNotSame('coffee', $cards[2]->interests()[0]);
        $this->assertSame('coffee', $cards[3]->interests()[0]);
    }

    #[Test]
    public function following_leans_on_the_price_of_what_was_liked(): void
    {
        $cards = $this->deck()->compose($this->pool(), [$this->card('coffee', 12000)], [], 1);

        // Half to twice 120 euro: the 60 and 120 euro coffee cards qualify, 15 and 30 do not.
        $this->assertGreaterThanOrEqual(6000, $cards[0]->price);
    }

    #[Test]
    public function two_noes_and_no_yes_leave_an_interest_out(): void
    {
        $cards = $this->deck()->compose($this->pool(), [], [$this->card('gaming'), $this->card('gaming')], 30);

        $this->assertNotContains('gaming', $this->interests($cards));
    }

    #[Test]
    public function one_no_is_not_enough_to_leave_an_interest_out(): void
    {
        $cards = $this->deck()->compose($this->pool(), [], [$this->card('gaming')], 30);

        $this->assertContains('gaming', $this->interests($cards));
    }

    #[Test]
    public function a_liked_interest_outweighs_a_single_no(): void
    {
        $scores = SwipeDeck::scores([$this->card('coffee')], [$this->card('coffee')]);

        $this->assertSame(0.5, $scores['coffee']);
    }

    #[Test]
    public function a_known_interest_is_followed_from_the_first_card(): void
    {
        // What is known about somebody counts as one like before any swipe (2026-09-29).
        $cards = $this->deck()->compose($this->pool(), [], [], 3, new DeckSeed(known: ['coffee']));

        $this->assertSame(['coffee', 'coffee'], [$cards[0]->interests()[0], $cards[1]->interests()[0]]);
        $this->assertNotSame('coffee', $cards[2]->interests()[0]);
    }

    #[Test]
    public function a_typical_interest_is_only_explored_first(): void
    {
        // "Mother: gardening" is a stereotype: shown first, not followed.
        $cards = $this->deck()->compose($this->pool(), [], [], 2, new DeckSeed(explore: ['gardening']));

        $this->assertSame('gardening', $cards[0]->interests()[0]);
        $this->assertNotSame('gardening', $cards[1]->interests()[0]);
    }

    #[Test]
    public function what_a_seed_rules_out_never_comes(): void
    {
        $pool = [
            ...$this->pool(),
            new TasteCard(9001, ['interest:reading', 'age:0-2'], [], 3000),
            new TasteCard(9002, ['interest:reading', 'recipient:child'], [], 3000),
        ];

        $ids = array_map(
            fn (TasteCard $c) => $c->id,
            $this->deck()->compose($pool, [], [], 60, new DeckSeed(avoid: ['drinks', 'gaming'], ageBand: '30-49', recipient: 'mother')),
        );
        $interests = $this->interests($this->deck()->compose($pool, [], [], 60, new DeckSeed(avoid: ['gaming'])));

        $this->assertNotContains(9001, $ids, 'a baby toy for somebody in their forties');
        $this->assertNotContains(9002, $ids, 'a product tagged for a child, for a mother');
        $this->assertNotContains('gaming', $interests);
    }

    #[Test]
    public function nothing_is_shown_twice(): void
    {
        $cards = $this->deck()->compose($this->pool(), [$this->card('music')], [], 40);
        $ids = array_map(fn (TasteCard $c) => $c->id, $cards);

        $this->assertSame($ids, array_values(array_unique($ids)));
    }
}
