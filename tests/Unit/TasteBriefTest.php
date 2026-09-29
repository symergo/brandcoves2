<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Enums\Market;
use App\Enums\Preference;
use App\Services\Gift\TasteBrief;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A brief survives being stored (roadmap step 4, part 1): what goes into
 * `cove_plans.brief` or `gift_landings.brief` comes back as the same brief,
 * and anything outside the gift vocabulary is dropped, never kept as a value
 * the engine would quietly match nothing with.
 */
class TasteBriefTest extends TestCase
{
    #[Test]
    public function a_brief_comes_back_from_its_array_unchanged(): void
    {
        $brief = new TasteBrief(
            market: Market::BeNl,
            interests: ['cooking', 'coffee'],
            preferences: [Preference::cases()[0]->value],
            budgetMin: 2000,
            budgetMax: 6000,
            avoid: ['alcohol'],
            relationship: 'father',
            occasion: 'birthday',
            ageBand: '50-64',
            query: 'espresso',
        );

        $back = TasteBrief::fromArray($brief->toArray(), Market::BeNl, 8);

        $this->assertSame($brief->toArray(), $back->toArray());
        $this->assertSame(8, $back->limit);
        $this->assertSame(Market::BeNl, $back->market);
    }

    #[Test]
    public function the_array_holds_only_what_somebody_decided(): void
    {
        $brief = new TasteBrief(market: Market::En, interests: ['gardening'], relationship: 'sibling');

        // No market, limit, exclusions or empty fields: those belong to one
        // run of the engine, or to whatever holds the brief.
        $this->assertSame(['relationship' => 'sibling', 'interests' => ['gardening']], $brief->toArray());
        $this->assertFalse($brief->isEmpty());
        $this->assertTrue((new TasteBrief(market: Market::En))->isEmpty());
    }

    #[Test]
    public function unknown_values_are_dropped_and_named(): void
    {
        $data = [
            'relationship' => 'uncle',
            'interests' => ['cooking', 'underwater basket weaving', 'COFFEE'],
            'occasion' => 'christmas',
            'ageBand' => '40-45',
            'preferences' => ['vintage', 'glittery'],
            'budgetMin' => -5,
            'budgetMax' => 4000,
        ];

        $brief = TasteBrief::fromArray($data, Market::BeFr);

        $this->assertNull($brief->relationship);
        $this->assertSame(['cooking', 'coffee'], $brief->interests);
        $this->assertSame('christmas', $brief->occasion);
        $this->assertNull($brief->ageBand);
        $this->assertSame(['vintage'], $brief->preferences);
        $this->assertNull($brief->budgetMin);
        $this->assertSame(4000, $brief->budgetMax);

        $problems = TasteBrief::problems($data);

        $this->assertSame(['relationship', 'ageBand', 'interests', 'preferences', 'budgetMin'], array_keys($problems));
        $this->assertStringContainsString('underwater basket weaving', $problems['interests']);
    }

    #[Test]
    public function a_budget_written_backwards_is_the_same_band(): void
    {
        $brief = TasteBrief::fromArray(['budgetMin' => 5000, 'budgetMax' => 2000], Market::En);

        $this->assertSame(2000, $brief->budgetMin);
        $this->assertSame(5000, $brief->budgetMax);
    }
}
