<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Gift\NextStep;
use App\Services\Gift\NextStepCandidate;
use App\Services\Gift\NextStepScorer;
use App\Services\Gift\PastGift;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * "Last year the moka pot, this year the grinder", without a database: the
 * three reasons a product follows on, never the same thing again, recent gifts
 * first. docs/features/gift-history.md.
 */
class NextStepScorerTest extends TestCase
{
    private const YEAR = 2026;

    /** @var array<string, array{triggers: list<string>, goes_with: list<string>}> */
    private const FAMILIES = [
        'coffee' => [
            'triggers' => ['moka', 'cafetière'],
            'goes_with' => ['koffiebonen', 'coffee beans', 'grinder', 'tea'],
        ],
        'shaving' => [
            'triggers' => ['razor'],
            'goes_with' => ['razor blades'],
        ],
    ];

    private function moka(?int $year = 2025, ?int $groupId = 10): PastGift
    {
        return new PastGift(
            source: PastGift::SENT,
            title: 'Bialetti Moka Express 3 kops',
            groupId: $groupId,
            year: $year,
            brand: 'Bialetti',
            category: 'Koffiezetters',
        );
    }

    /**
     * @param  list<PastGift>  $past
     * @param  list<NextStepCandidate>  $candidates
     * @param  array<int, array<int, int>>  $links
     * @param  list<int>  $exclude
     * @return list<NextStep>
     */
    private function rank(array $past, array $candidates, array $links = [], array $exclude = [], ?int $budget = null, int $limit = 4): array
    {
        return (new NextStepScorer)->rank($past, $candidates, $links, self::FAMILIES, $exclude, $budget, self::YEAR, $limit);
    }

    #[Test]
    public function what_goes_with_a_past_gift_follows_it(): void
    {
        $steps = $this->rank([$this->moka()], [
            new NextStepCandidate(1, 'Lavazza Koffiebonen 1 kg', 'Lavazza', 'Koffie', 1500),
            new NextStepCandidate(2, 'JBL Bluetooth speaker', 'JBL', 'Audio', 4000),
        ]);

        $this->assertCount(1, $steps, 'A speaker has nothing to do with a moka pot.');
        $this->assertSame(1, $steps[0]->groupId);
        $this->assertSame(NextStep::GOES_WITH, $steps[0]->reason);
        $this->assertSame('Bialetti Moka Express 3 kops', $steps[0]->after);
    }

    #[Test]
    public function words_match_at_the_start_of_a_word_and_ignore_accents(): void
    {
        $scorer = new NextStepScorer;

        $this->assertTrue($scorer->goesWith('Cafetière 8 tassen', 'Green tea', self::FAMILIES));
        $this->assertFalse($scorer->goesWith('Cafetière 8 tassen', 'Steak knives', self::FAMILIES), '"tea" must not find "steak".');
        $this->assertTrue($scorer->goesWith('Moka pot', 'Burr grinders', self::FAMILIES), '"grinder" finds "grinders".');
        $this->assertSame(['coffee'], $scorer->familiesOf('Cafetiere', self::FAMILIES));
    }

    #[Test]
    public function products_people_keep_together_follow_and_more_people_count_for_more(): void
    {
        $steps = $this->rank([$this->moka()], [
            new NextStepCandidate(20, 'Espressokopjes set van 4', null, 'Servies', 2000),
            new NextStepCandidate(21, 'Melkkan', null, 'Servies', 2000),
        ], links: [20 => [10 => 5], 21 => [10 => 20]]);

        $this->assertSame([21, 20], array_map(fn (NextStep $s) => $s->groupId, $steps));
        $this->assertSame(NextStep::OFTEN_TOGETHER, $steps[0]->reason);
        $this->assertEqualsWithDelta(1.0, $steps[0]->score, 0.0001, 'Twenty people is a full link.');
        $this->assertEqualsWithDelta(0.625, $steps[1]->score, 0.0001, 'Five people is the floor.');
    }

    #[Test]
    public function a_link_at_the_floor_weighs_less_than_what_goes_with_it(): void
    {
        $steps = $this->rank([$this->moka()], [
            new NextStepCandidate(20, 'Espressokopjes set van 4', null, 'Servies', 2000),
            new NextStepCandidate(1, 'Lavazza Koffiebonen 1 kg', 'Lavazza', 'Koffie', 1500),
        ], links: [20 => [10 => 5]]);

        $this->assertSame([1, 20], array_map(fn (NextStep $s) => $s->groupId, $steps));
    }

    #[Test]
    public function the_same_brand_counts_more_for_another_kind_of_thing(): void
    {
        $steps = $this->rank([$this->moka()], [
            new NextStepCandidate(30, 'Bialetti Venus inductie', 'Bialetti', 'Koffiezetters', 3000),
            new NextStepCandidate(31, 'Bialetti Steelpan 16 cm', 'Bialetti', 'Pannen', 3000),
        ]);

        $this->assertSame([31, 30], array_map(fn (NextStep $s) => $s->groupId, $steps));
        $this->assertSame(NextStep::SAME_BRAND, $steps[0]->reason);
        $this->assertEqualsWithDelta(0.6, $steps[0]->score, 0.0001);
        $this->assertEqualsWithDelta(0.4, $steps[1]->score, 0.0001);
    }

    #[Test]
    public function a_second_reason_adds_to_the_first(): void
    {
        [$step] = $this->rank([$this->moka()], [
            new NextStepCandidate(40, 'Bialetti grinder', 'Bialetti', 'Molens', 3000),
        ]);

        $this->assertSame(NextStep::GOES_WITH, $step->reason);
        $this->assertEqualsWithDelta(0.8 + 0.25 * 0.6, $step->score, 0.0001);
    }

    #[Test]
    public function another_of_the_same_thing_is_never_a_next_step(): void
    {
        $steps = $this->rank([$this->moka()], [
            new NextStepCandidate(50, 'Bialetti Moka Express 6 kops', 'Bialetti', 'Koffiezetters', 3500),
        ], links: [50 => [10 => 20]]);

        $this->assertSame([], $steps, 'The six-cup pot after the three-cup one, however many people keep both.');
    }

    #[Test]
    public function past_gifts_their_merges_and_what_is_over_budget_are_left_out(): void
    {
        $steps = $this->rank([$this->moka()], [
            new NextStepCandidate(1, 'Lavazza Koffiebonen 1 kg', 'Lavazza', 'Koffie', 1500),
            new NextStepCandidate(2, 'Illy coffee beans', 'Illy', 'Koffie', 1200),
            new NextStepCandidate(3, 'Electric burr grinder', 'Sage', 'Molens', 20000),
        ], exclude: [1], budget: 5000);

        $this->assertSame([2], array_map(fn (NextStep $s) => $s->groupId, $steps));
    }

    #[Test]
    public function recent_gifts_count_more_than_old_ones(): void
    {
        $old = new PastGift(PastGift::SENT, 'Cafetière 8 tassen', 11, 2018);
        $recent = $this->moka(2025);

        $steps = $this->rank([$recent, $old], [
            new NextStepCandidate(60, 'Handmatige grinder', null, 'Molens', 3000),
        ]);

        $this->assertSame('Bialetti Moka Express 3 kops', $steps[0]->after, 'Last year beats eight years ago.');

        $onlyOld = $this->rank([$old], [new NextStepCandidate(60, 'Handmatige grinder', null, 'Molens', 3000)]);
        $this->assertEqualsWithDelta(0.8 * 0.4, $onlyOld[0]->score, 0.0001, 'An old gift still counts, at the floor.');
    }

    #[Test]
    public function near_twins_show_once(): void
    {
        $steps = $this->rank([$this->moka()], [
            new NextStepCandidate(1, 'Lavazza Koffiebonen Oro 1 kg', 'Lavazza', 'Koffie', 1500),
            new NextStepCandidate(2, 'Lavazza Koffiebonen Oro 500 g', 'Lavazza', 'Koffie', 900),
            new NextStepCandidate(3, 'Handmatige grinder', null, 'Molens', 3000),
            new NextStepCandidate(4, 'Green tea', null, 'Thee', 800),
        ]);

        // All four go with the moka pot and score the same; the 500 g beans
        // are the 1 kg beans again. With one past gift the row still fills.
        $this->assertSame([1, 3, 4], array_map(fn (NextStep $s) => $s->groupId, $steps));
    }

    #[Test]
    public function a_second_past_gift_gets_a_place_before_a_third_idea_for_the_first(): void
    {
        $razor = new PastGift(PastGift::SENT, 'Safety razor', null, 2020);

        $steps = $this->rank([$this->moka(), $razor], [
            new NextStepCandidate(1, 'Lavazza Koffiebonen 1 kg', null, 'Koffie', 1500),
            new NextStepCandidate(2, 'Handmatige grinder', null, 'Molens', 3000),
            new NextStepCandidate(3, 'Green tea', null, 'Thee', 800),
            new NextStepCandidate(4, 'Razor blades 10 pack', null, 'Scheren', 900),
        ], limit: 3);

        // The blades score far lower (an old gift), and still take the third place.
        $this->assertSame([1, 2, 4], array_map(fn (NextStep $s) => $s->groupId, $steps));
    }

    #[Test]
    public function the_same_inputs_give_the_same_row(): void
    {
        $candidates = [
            new NextStepCandidate(8, 'Handmatige grinder', null, 'Molens', 3000),
            new NextStepCandidate(7, 'Illy coffee beans', null, 'Koffie', 1200),
        ];

        $first = $this->rank([$this->moka()], $candidates);
        $second = $this->rank([$this->moka()], array_reverse($candidates));

        $this->assertSame([7, 8], array_map(fn (NextStep $s) => $s->groupId, $first), 'A tie is broken by the lower id.');
        $this->assertEquals($first, $second);
    }

    #[Test]
    public function nothing_given_is_nothing_to_follow(): void
    {
        $this->assertSame([], $this->rank([], [new NextStepCandidate(1, 'Illy coffee beans', null, null, 1200)]));
    }
}
