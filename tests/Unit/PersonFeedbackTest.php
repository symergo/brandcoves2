<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\ProductGroup;
use App\Services\Gift\CrowdVotes;
use App\Services\Gift\PersonFeedback;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The arithmetic of the thumbs, without a database: what one saved person's
 * thumbs lend to a candidate (PersonFeedback) and what everybody's say once
 * enough people voted (CrowdVotes). docs/features/find-a-gift.md.
 */
class PersonFeedbackTest extends TestCase
{
    private function group(int $id, array $interests, ?string $category, ?string $brand): ProductGroup
    {
        $group = new ProductGroup;
        $group->id = $id;
        $group->gift_tags = array_map(fn (string $i) => "interest:{$i}", $interests);
        $group->crowd_tags = [];
        $group->category = $category;
        $group->brand = $brand;

        return $group;
    }

    #[Test]
    public function a_like_lends_interest_category_and_brand_in_that_order(): void
    {
        $liked = $this->group(1, ['cooking'], 'Pannen', 'Le Creuset');
        $feedback = PersonFeedback::fromGroups([$liked], []);

        $this->assertSame([1], $feedback->liked);
        $this->assertEqualsWithDelta(1.0, $feedback->affinity($liked), 1e-9);
        // The brand folds like everywhere else: "LE CREUSET" is Le Creuset.
        $this->assertEqualsWithDelta(1.0, $feedback->affinity($this->group(2, ['cooking'], 'pannen', 'LE CREUSET')), 1e-9);
        $this->assertEqualsWithDelta(0.85, $feedback->affinity($this->group(3, ['cooking'], 'Pannen', 'Other')), 1e-9);
        $this->assertEqualsWithDelta(0.5, $feedback->affinity($this->group(4, ['cooking'], 'Boeken', null)), 1e-9);
        $this->assertEqualsWithDelta(0.15, $feedback->affinity($this->group(5, ['gaming'], 'Games', 'Le Creuset')), 1e-9);
        $this->assertSame(0.0, $feedback->affinity($this->group(6, ['gaming'], 'Games', 'Sony')));
    }

    #[Test]
    public function a_dislike_costs_half_what_a_like_gives_and_likes_saturate(): void
    {
        $no = $this->group(1, ['cooking'], 'Pannen', 'A');
        $feedback = PersonFeedback::fromGroups([], [$no]);

        $this->assertSame([1], $feedback->disliked);
        $this->assertEqualsWithDelta(-0.5, $feedback->affinity($this->group(2, ['cooking'], 'Pannen', 'A')), 1e-9);

        // Three liked cookbooks do not count three times.
        $many = PersonFeedback::fromGroups([
            $this->group(1, ['cooking'], 'Boeken', 'X'),
            $this->group(2, ['cooking'], 'Boeken', 'Y'),
            $this->group(3, ['cooking'], 'Boeken', 'Z'),
        ], []);
        $this->assertEqualsWithDelta(0.85, $many->affinity($this->group(4, ['cooking'], 'Boeken', 'Q')), 1e-9);

        $this->assertTrue(PersonFeedback::none()->isEmpty());
        $this->assertSame(0.0, PersonFeedback::none()->affinity($no));
    }

    #[Test]
    public function the_crowd_counts_only_from_the_threshold_and_prefers_the_same_kind_of_person(): void
    {
        // Four people: nothing, however unanimous.
        $this->assertSame(0.0, CrowdVotes::strength(4, 4, 4, 4, 5));

        // Five, all up: approval 1 at the lowest confidence.
        $this->assertEqualsWithDelta(0.7, CrowdVotes::strength(5, 5, 5, 5, 5), 1e-9);

        // Twenty: fully sure.
        $this->assertEqualsWithDelta(1.0, CrowdVotes::strength(20, 20, 20, 20, 5), 1e-9);

        // A net "no" is halved: five down is -0.35, not -0.7.
        $this->assertEqualsWithDelta(-0.35, CrowdVotes::strength(5, 0, 5, 0, 5), 1e-9);

        // Too few for this kind of person: all votes decide.
        $this->assertEqualsWithDelta(0.7 * 0.6, CrowdVotes::strength(2, 2, 5, 4, 5), 1e-9);

        // Enough for this kind of person: theirs decide, whatever the rest say.
        $this->assertEqualsWithDelta(0.7, CrowdVotes::strength(5, 5, 9, 5, 5), 1e-9);

        // A threshold of one is treated as one, never zero.
        $this->assertSame(0.0, CrowdVotes::strength(0, 0, 0, 0, 0));
    }
}
