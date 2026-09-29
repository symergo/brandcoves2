<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Enums\Market;
use App\Services\Gift\TasteCard;
use App\Services\Gift\TasteChoice;
use App\Services\Gift\TasteProfile;
use App\Services\Gift\TasteProfiler;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Choices in, a taste out: the arithmetic of This or that.
 *
 * Every rule in TasteProfiler's docblock has a test here, because each one
 * is the kind of number somebody later "cleans up": that passing over is
 * half a dislike, that an interest needs two good rounds, that avoid needs
 * two bad ones and no good one, and how the price band is cut.
 */
class TasteProfilerTest extends TestCase
{
    private int $id = 0;

    /** @param list<string> $tags */
    private function card(array $tags, ?int $price = 3000, array $crowd = []): TasteCard
    {
        return new TasteCard(++$this->id, $tags, $crowd, $price);
    }

    private function profiler(): TasteProfiler
    {
        return new TasteProfiler(crowdWeight: 0.75, floor: 500, ceiling: 50000);
    }

    #[Test]
    public function an_interest_picked_twice_is_one_they_love(): void
    {
        $choices = [];

        for ($i = 0; $i < 2; $i++) {
            $coffee = $this->card(['interest:coffee']);
            $choices[] = TasteChoice::pair($coffee, $this->card(['interest:gaming']), $coffee->id);
        }

        $profile = $this->profiler()->profile($choices);

        $this->assertSame(['coffee'], $profile->interests);
        $this->assertSame(2.0, $profile->scores['coffee']);
        $this->assertSame(-1.0, $profile->scores['gaming']);
        $this->assertSame(2, $profile->answered);
    }

    #[Test]
    public function the_strongest_interest_comes_first(): void
    {
        $choices = [];

        foreach ([['cooking', 'music'], ['cooking', 'fitness'], ['cooking', 'tech'], ['coffee', 'music'], ['coffee', 'yoga']] as [$win, $lose]) {
            $winner = $this->card(["interest:{$win}"]);
            $choices[] = TasteChoice::pair($winner, $this->card(["interest:{$lose}"]), $winner->id);
        }

        $this->assertSame(['cooking', 'coffee'], $this->profiler()->profile($choices)->interests);
    }

    #[Test]
    public function one_pick_is_not_enough_when_other_interests_have_two(): void
    {
        $choices = [];

        foreach (['cooking', 'cooking', 'art'] as $win) {
            $winner = $this->card(["interest:{$win}"]);
            $choices[] = TasteChoice::pair($winner, $this->card(['interest:tech']), $winner->id);
        }

        $this->assertSame(['cooking'], $this->profiler()->profile($choices)->interests);
    }

    #[Test]
    public function a_short_session_still_names_the_best_one_or_two(): void
    {
        $a = $this->card(['interest:reading']);
        $b = $this->card(['interest:gardening']);

        $profile = $this->profiler()->profile([
            TasteChoice::pair($a, $this->card(['interest:cars']), $a->id),
            TasteChoice::pair($b, $this->card(['interest:football']), $b->id),
        ]);

        $this->assertSame(['gardening', 'reading'], $profile->interests);
    }

    #[Test]
    public function a_tag_both_cards_share_is_not_what_the_choice_was_about(): void
    {
        $choices = [];

        for ($i = 0; $i < 3; $i++) {
            $winner = $this->card(['interest:coffee', 'preference:design']);
            $choices[] = TasteChoice::pair($winner, $this->card(['interest:tech', 'preference:design']), $winner->id);
        }

        $profile = $this->profiler()->profile($choices);

        $this->assertSame([], $profile->preferences);
        $this->assertArrayNotHasKey('design', $profile->scores);
    }

    #[Test]
    public function a_crowd_tag_counts_at_three_quarters(): void
    {
        $choices = [];

        for ($i = 0; $i < 2; $i++) {
            $winner = $this->card([], crowd: ['interest:baking']);
            $choices[] = TasteChoice::pair($winner, $this->card(['interest:cars']), $winner->id);
        }

        $profile = $this->profiler()->profile($choices);

        $this->assertSame(1.5, $profile->scores['baking']);
        $this->assertSame(['baking'], $profile->interests);
    }

    #[Test]
    public function two_bad_rounds_and_no_good_one_make_an_avoid(): void
    {
        $choices = [];

        for ($i = 0; $i < 2; $i++) {
            $winner = $this->card(['interest:cooking']);
            $choices[] = TasteChoice::pair($winner, $this->card(['interest:gaming']), $winner->id);
        }

        $this->assertSame(['gaming'], $this->profiler()->profile($choices)->avoid);
    }

    #[Test]
    public function one_dislike_is_not_an_avoid(): void
    {
        $profile = $this->profiler()->profile([
            TasteChoice::single($this->card(['interest:gaming']), TasteChoice::DISLIKE),
        ]);

        $this->assertSame([], $profile->avoid);
        $this->assertSame(-1.0, $profile->scores['gaming']);
    }

    #[Test]
    public function an_interest_ever_picked_is_never_avoided(): void
    {
        $gaming = $this->card(['interest:gaming']);

        $choices = [
            TasteChoice::pair($gaming, $this->card(['interest:tech']), $gaming->id),
            TasteChoice::single($this->card(['interest:gaming']), TasteChoice::DISLIKE),
            TasteChoice::single($this->card(['interest:gaming']), TasteChoice::DISLIKE),
        ];

        $profile = $this->profiler()->profile($choices);

        $this->assertNotContains('gaming', $profile->avoid);
    }

    #[Test]
    public function skips_teach_nothing(): void
    {
        $profile = $this->profiler()->profile([
            TasteChoice::pair($this->card(['interest:coffee']), $this->card(['interest:tech']), null),
            TasteChoice::single($this->card(['interest:tech']), null),
        ]);

        $this->assertSame([], $profile->interests);
        $this->assertSame([], $profile->scores);
        $this->assertSame(0, $profile->answered);
        $this->assertTrue($profile->isEmpty());
    }

    #[Test]
    public function the_price_band_is_the_middle_of_what_was_picked_widened_and_rounded(): void
    {
        $choices = [];

        foreach ([2000, 3000, 4000, 5000, 6000] as $price) {
            $winner = $this->card([], $price);
            $choices[] = TasteChoice::pair($winner, $this->card([], $price * 3), $winner->id);
        }

        $profile = $this->profiler()->profile($choices);

        // Quartiles 30 and 50 euro; 24 rounds down to 20, 60 stays 60.
        $this->assertSame(2000, $profile->budgetMin);
        $this->assertSame(6000, $profile->budgetMax);
    }

    #[Test]
    public function two_prices_are_not_a_band(): void
    {
        $a = $this->card([], 2000);
        $b = $this->card([], 4000);

        $profile = $this->profiler()->profile([
            TasteChoice::pair($a, $this->card([], 9000), $a->id),
            TasteChoice::single($b, TasteChoice::LIKE),
        ]);

        $this->assertNull($profile->budgetMin);
        $this->assertNull($profile->budgetMax);
    }

    #[Test]
    public function the_band_stays_inside_what_the_gift_engine_suggests(): void
    {
        $choices = [];

        foreach ([45000, 48000, 49000] as $price) {
            $choices[] = TasteChoice::single($this->card([], $price), TasteChoice::LIKE);
        }

        $profile = $this->profiler()->profile($choices);

        $this->assertSame(50000, $profile->budgetMax);
        $this->assertLessThan($profile->budgetMax, $profile->budgetMin);
    }

    #[Test]
    public function never_both_ends_of_one_taste_axis(): void
    {
        $choices = [];

        foreach (['vintage', 'vintage', 'vintage', 'modern', 'modern', 'quirky', 'quirky'] as $pole) {
            $choices[] = TasteChoice::single($this->card(["preference:{$pole}"]), TasteChoice::LIKE);
        }

        $this->assertSame(['vintage', 'quirky'], $this->profiler()->profile($choices)->preferences);
    }

    #[Test]
    public function an_avoided_interest_reaches_the_brief_in_the_tags_spelling(): void
    {
        $profile = new TasteProfile(interests: ['coffee'], avoid: ['gaming'], budgetMin: 2000, budgetMax: 6000);

        $brief = $profile->brief(Market::BeNl, 8, [1, 2]);

        $this->assertSame(['coffee'], $brief->interests);
        $this->assertSame(['interest:gaming'], $brief->avoid);
        $this->assertSame(['gaming'], $brief->avoidedInterests());
        $this->assertSame([], $brief->avoidWords());
        $this->assertSame([1, 2], $brief->excludeGroupIds);
    }

    #[Test]
    public function merging_adds_to_what_is_stored_and_drops_contradictions(): void
    {
        $profile = new TasteProfile(interests: ['coffee', 'gaming'], avoid: ['cooking'], preferences: ['vintage']);

        $merged = $profile->mergedWith([
            'interests' => ['cooking', 'reading'],
            'avoid' => ['wol', 'interest:gaming'],
            'preferences' => ['modern', 'quirky'],
        ]);

        // Today's first; cooking is avoided today so it comes off.
        $this->assertSame(['coffee', 'gaming', 'reading'], $merged['interests']);
        // gaming is loved today so it comes off the avoid list; a typed word stays.
        $this->assertSame(['wol', 'interest:cooking'], $merged['avoid']);
        // vintage replaces modern on the era axis; quirky's axis was not asked today.
        $this->assertSame(['vintage', 'quirky'], $merged['preferences']);
        // Vibe and values were removed site-wide (2026-09-29); the pairs carry the feel.
        $this->assertSame(['interests', 'avoid', 'preferences'], array_keys($merged));
    }

    /**
     * This or that together (docs/features/taste-together.md): two people who
     * each picked cooking once agree on it, where neither alone would.
     */
    #[Test]
    public function two_runs_that_each_pick_an_interest_once_agree_on_it(): void
    {
        $run = function (string $other): array {
            $cooking = $this->card(['interest:cooking']);
            $music = $this->card(['interest:music']);

            return [
                TasteChoice::pair($cooking, $this->card(["interest:{$other}"]), $cooking->id),
                TasteChoice::pair($music, $this->card(['interest:reading']), $music->id),
            ];
        };

        $first = $run('gaming');
        $second = $run('fitness');

        $this->assertSame(['cooking', 'music'], $this->profiler()->profile($first)->interests, 'one run alone is a thin reading');
        $this->assertSame(2, $this->profiler()->profile($first)->answered);

        $together = $this->profiler()->combined([$first, $second]);

        $this->assertSame(['cooking', 'music'], $together->interests);
        $this->assertSame(2.0, $together->scores['cooking']);
        $this->assertSame(4, $together->answered);
    }

    #[Test]
    public function one_players_pick_overrules_the_others_dislikes(): void
    {
        $dislike = fn () => [TasteChoice::single($this->card(['interest:gaming']), TasteChoice::DISLIKE)];

        // Two people disliked gaming: avoided.
        $this->assertSame(['gaming'], $this->profiler()->combined([$dislike(), $dislike()])->avoid);

        // A third picked it once: never avoided, whatever else happened to it.
        $gaming = $this->card(['interest:gaming']);
        $picked = [TasteChoice::pair($gaming, $this->card(['interest:yoga']), $gaming->id)];

        $this->assertSame([], $this->profiler()->combined([$dislike(), $dislike(), $picked])->avoid);
    }

    #[Test]
    public function no_runs_is_an_empty_profile(): void
    {
        $this->assertTrue($this->profiler()->combined([])->isEmpty());
    }
}
