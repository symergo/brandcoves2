<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Enums\Market;
use App\Services\Gift\CrowdPickMatcher;
use App\Services\Gift\TasteBrief;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The rules of "chosen by others for someone like them", without a database:
 * which kinds of person a brief describes, the five-people threshold, and how
 * a pair outranks a single fact. docs/features/crowd-picks.md.
 */
class CrowdPickMatcherTest extends TestCase
{
    private function matcher(): CrowdPickMatcher
    {
        return new CrowdPickMatcher;
    }

    #[Test]
    public function a_brief_is_its_facts_and_every_pair_of_them_in_byte_order(): void
    {
        $brief = new TasteBrief(
            market: Market::BeNl,
            interests: ['Cooking', 'oude motoren'],
            relationship: 'father',
            occasion: 'birthday',
            ageBand: '50-64',
        );

        $this->assertSame(
            ['age:50-64', 'interest:cooking', 'occasion:birthday', 'recipient:father'],
            $this->matcher()->facts($brief),
            'Free text ("oude motoren") matches no list, so it is not a fact.',
        );

        $contexts = $this->matcher()->contexts($brief);

        $this->assertCount(4 + 6, $contexts);
        $this->assertContains('interest:cooking+recipient:father', $contexts);
        $this->assertNotContains('recipient:father+interest:cooking', $contexts);
    }

    #[Test]
    public function a_relationship_or_occasion_outside_the_vocabulary_is_no_fact(): void
    {
        $brief = new TasteBrief(market: Market::BeNl, relationship: 'my mum', occasion: 'just because', ageBand: '40ish');

        $this->assertSame([], $this->matcher()->contexts($brief));
    }

    #[Test]
    public function at_most_four_interests_are_looked_up(): void
    {
        $brief = new TasteBrief(market: Market::BeNl, interests: ['cooking', 'coffee', 'gaming', 'music', 'reading', 'gardening']);

        $facts = $this->matcher()->facts($brief);

        $this->assertCount(4, $facts);
        $this->assertNotContains('interest:reading', $facts);
    }

    #[Test]
    public function nothing_counts_below_the_threshold(): void
    {
        $picks = $this->matcher()->score([
            ['group_id' => 1, 'context' => 'recipient:father', 'owners' => 4],
            ['group_id' => 2, 'context' => 'recipient:father', 'owners' => 5],
        ], 5);

        $this->assertSame([2], array_keys($picks));
        $this->assertSame(5, $picks[2]->owners);
    }

    #[Test]
    public function the_threshold_is_never_below_one_person(): void
    {
        $picks = $this->matcher()->score([['group_id' => 1, 'context' => 'recipient:father', 'owners' => 0]], 0);

        $this->assertSame([], $picks);
    }

    #[Test]
    public function a_pair_outranks_a_single_fact_with_the_same_count(): void
    {
        $picks = $this->matcher()->score([
            ['group_id' => 1, 'context' => 'recipient:father', 'owners' => 6],
            ['group_id' => 2, 'context' => 'interest:cooking+recipient:father', 'owners' => 6],
        ], 5);

        $this->assertSame([2, 1], array_keys($picks), 'Strongest first.');
        $this->assertGreaterThan($picks[1]->strength, $picks[2]->strength);
    }

    #[Test]
    public function more_people_is_surer_up_to_four_times_the_threshold(): void
    {
        $at = fn (int $owners) => $this->matcher()->score([['group_id' => 1, 'context' => 'a:b+c:d', 'owners' => $owners]], 5)[1]->strength;

        $this->assertEqualsWithDelta(0.7, $at(5), 0.001);
        $this->assertGreaterThan($at(5), $at(10));
        $this->assertEqualsWithDelta(1.0, $at(20), 0.001);
        $this->assertEqualsWithDelta(1.0, $at(200), 0.001);
    }

    #[Test]
    public function the_best_context_sets_the_strength_and_every_match_names_its_facts(): void
    {
        $picks = $this->matcher()->score([
            ['group_id' => 7, 'context' => 'occasion:birthday', 'owners' => 30],
            ['group_id' => 7, 'context' => 'interest:cooking+recipient:father', 'owners' => 5],
        ], 5);

        $pick = $picks[7];

        // Single at full confidence 0.6 against a pair at the floor 0.7.
        $this->assertEqualsWithDelta(0.7, $pick->strength, 0.001);
        $this->assertSame(5, $pick->owners);
        $this->assertSame(['interest:cooking', 'occasion:birthday', 'recipient:father'], $pick->tags);
    }

    #[Test]
    public function an_occasion_alone_is_not_about_the_person(): void
    {
        $picks = $this->matcher()->score([
            ['group_id' => 1, 'context' => 'occasion:christmas', 'owners' => 9],
            ['group_id' => 2, 'context' => 'age:65+', 'owners' => 9],
        ], 5);

        $this->assertFalse($picks[1]->isAboutThePerson(), 'It still lifts, but the card makes no claim about the person.');
        $this->assertTrue($picks[2]->isAboutThePerson());
    }
}
