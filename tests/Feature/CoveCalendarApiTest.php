<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\CoveKind;
use App\Enums\Market;
use App\Models\ApiToken;
use App\Models\CovePlan;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The editorial year over HTTP, and the two ways to act on it.
 *
 * The Cove calendar screen could draw the year and draft one day of it; the API
 * could only ask for "the next N" and take whatever the walk found. These pin
 * the three things that made that gap matter.
 */
class CoveCalendarApiTest extends TestCase
{
    use RefreshDatabase;

    private const TODAY = '2027-04-15';

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse(self::TODAY)->setTime(12, 0));
    }

    #[Test]
    public function the_year_is_drawn_from_the_config_not_from_rows(): void
    {
        /*
         * A fresh environment that has never run `bc:refresh-discovery` still
         * gets the complete year: plans are joined where they exist and their
         * absence is a state — "nothing planned" — rather than a gap. That is
         * also what makes it recurring rather than a report on this year.
         */
        $response = $this->withToken($this->key([ApiToken::READ]))
            ->getJson('/api/editorial/calendar?market=be-nl&year=2029')
            ->assertOk()
            ->assertJsonPath('year', 2029)
            ->assertJsonCount(12, 'months');

        $this->assertGreaterThan(0, $response->json('summary.days'));
        $this->assertSame(0, $response->json('summary.daysPlanned'));

        // The ~270 rotation days are not listed, and the reply says so rather
        // than leaving their absence to read as "nothing happens in the gaps".
        $this->assertStringContainsString('evergreen rotation', (string) $response->json('note'));
    }

    #[Test]
    public function one_named_day_can_be_drafted_by_date(): void
    {
        /*
         * The count form walks forward filling whatever it finds, which is right
         * for topping a queue up and wrong for somebody pointing at a date:
         * asking for four months of plans to get the one you meant is not a
         * reasonable trade, and neither is undoing the other hundred and
         * nineteen.
         */
        $day = $this->firstNamedDay();

        $this->withToken($this->key([ApiToken::READ, ApiToken::WRITE]))
            ->postJson('/api/editorial/calendar/draft', [
                'market' => Market::BeNl->value,
                'date' => $day,
            ])
            ->assertStatus(201)
            ->assertJsonPath('data.date', $day)
            ->assertJsonPath('data.status', 'draft');

        $this->assertDatabaseHas('cove_plans', [
            'market' => Market::BeNl->value,
            'kind' => CoveKind::Daily->value,
            'drop_date' => $day,
        ]);
    }

    #[Test]
    public function a_day_that_is_already_planned_says_so_rather_than_adding_a_second(): void
    {
        // The unique index allows exactly one Daily per market per day, so a
        // second attempt is a caller working from a stale view of the calendar —
        // and "somebody already decided this" is a different answer from
        // "nothing to draft here".
        $day = $this->firstNamedDay();

        CovePlan::create([
            'market' => Market::BeNl->value,
            'kind' => CoveKind::Daily->value,
            'drop_date' => $day,
            'title' => 'Al bedacht',
            'status' => 'draft',
        ]);

        $this->withToken($this->key([ApiToken::READ, ApiToken::WRITE]))
            ->postJson('/api/editorial/calendar/draft', [
                'market' => Market::BeNl->value,
                'date' => $day,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('date');

        $this->assertSame(1, CovePlan::query()->whereDate('drop_date', $day)->count());
    }

    #[Test]
    public function occasions_only_skips_the_evergreen_rotation(): void
    {
        /*
         * The gap that reads as working. `themeFor()` falls back to the rotation
         * for any date with no named day, so an unfiltered walk hands back the
         * next N unplanned *dates* — mostly rotation themes, which claim nothing
         * about their date and give a curator nothing to react to.
         *
         * `PlanDrafter` writes a different note for each, which is how they are
         * told apart here and how a second run avoids re-drafting a renamed one.
         */
        $this->withToken($this->key([ApiToken::READ, ApiToken::WRITE]))
            ->postJson('/api/editorial/coves/drafts', [
                'market' => Market::BeNl->value,
                'kind' => CoveKind::Daily->value,
                'count' => 8,
                'withProducts' => false,
                'occasionsOnly' => true,
            ])
            ->assertStatus(201);

        $notes = CovePlan::query()->pluck('note')->all();

        $this->assertNotEmpty($notes);

        foreach ($notes as $note) {
            $this->assertStringNotContainsString('Rotation theme', (string) $note);
        }
    }

    #[Test]
    public function the_unfiltered_walk_still_fills_every_date(): void
    {
        // The default has to stay as it was: the calendar wants every day
        // filled, and that is what `bc:plan-coves` is for.
        $this->withToken($this->key([ApiToken::READ, ApiToken::WRITE]))
            ->postJson('/api/editorial/coves/drafts', [
                'market' => Market::BeNl->value,
                'kind' => CoveKind::Daily->value,
                'count' => 8,
                'withProducts' => false,
            ])
            ->assertStatus(201)
            ->assertJsonPath('count', 8);

        // Consecutive days, because nothing was skipped.
        $dates = CovePlan::query()->orderBy('drop_date')->pluck('drop_date');

        $this->assertSame(
            CarbonImmutable::parse(self::TODAY)->addDay()->toDateString(),
            CarbonImmutable::parse($dates->first())->toDateString(),
        );
    }

    #[Test]
    public function a_read_key_cannot_draft(): void
    {
        // Drafting writes rows. Reading the year does not.
        $this->withToken($this->key([ApiToken::READ]))
            ->postJson('/api/editorial/calendar/draft', [
                'market' => Market::BeNl->value,
                'date' => $this->firstNamedDay(),
            ])
            ->assertStatus(403);
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    /** @param list<string> $abilities */
    private function key(array $abilities): string
    {
        return ApiToken::issue('calendar key', $abilities)['token'];
    }

    /**
     * The next date the shipped calendar names, read from the API itself.
     *
     * Taken from the config rather than hard-coded, so this does not become a
     * test that fails the day somebody edits `observances.php`.
     */
    private function firstNamedDay(): string
    {
        $months = $this->withToken($this->key([ApiToken::READ]))
            ->getJson('/api/editorial/calendar?market=be-nl&year=2027')
            ->json('months');

        $tomorrow = CarbonImmutable::parse(self::TODAY)->addDay()->toDateString();

        foreach ($months as $month) {
            foreach ($month['days'] ?? [] as $day) {
                if (($day['date'] ?? '') >= $tomorrow && ($day['plan'] ?? null) === null) {
                    return $day['date'];
                }
            }
        }

        $this->fail('The shipped calendar names no upcoming day in 2027.');
    }
}
