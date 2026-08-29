<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\CoveKind;
use App\Enums\Market;
use App\Filament\Resources\CovePlans\Pages\ListCovePlans;
use App\Models\CovePlan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The planner screen: one tab per kind, and a button that fills the one you are on.
 *
 * Two strips: kind, then market. They narrow each other, which is the part
 * worth a test — a second axis that silently replaced the first would look
 * exactly like one that composed with it, on any screen with one plan per
 * market.
 *
 * Smoke-level, like the curation screen's test and for the same reason — the
 * decisions worth pinning hard live in `PlanDrafter`, which is tested directly.
 * What a service test cannot see is the panel being decorative: a tab that
 * filters nothing and an action that resolves to nothing look exactly like ones
 * that work, right up until somebody uses what they produced.
 */
class CovePlannerScreenTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function each_kind_has_a_tab_that_filters_to_it(): void
    {
        $daily = $this->plan(CoveKind::Daily, ['drop_date' => today()->addDay()]);
        $guide = $this->plan(CoveKind::Guide, ['slug' => 'beste-koptelefoon']);

        $component = Livewire::actingAs($this->admin())->test(ListCovePlans::class);

        // Every kind, plus an "All" that means it. The set has to follow the
        // enum, or a kind added later is a section of the planner nobody can
        // reach.
        $this->assertSame(
            ['all', ...CoveKind::values()],
            array_keys($component->instance()->getTabs()),
        );

        $component->set('activeTab', CoveKind::Guide->value)
            ->assertCanSeeTableRecords([$guide])
            ->assertCanNotSeeTableRecords([$daily]);
    }

    #[Test]
    public function each_market_has_a_tab_that_filters_to_it(): void
    {
        $belgian = $this->plan(CoveKind::Guide, ['slug' => 'be-guide']);
        $dutch = $this->plan(CoveKind::Guide, ['slug' => 'nl-guide', 'market' => Market::NlNl->value]);

        $component = Livewire::actingAs($this->admin())->test(ListCovePlans::class);

        // Every market, including the unpublished ones: an editor's job
        // includes filling the market that has not opened yet.
        $this->assertSame(
            ['all', ...Market::values()],
            array_keys($component->instance()->getMarketTabs()),
        );

        $component->set('activeMarket', Market::NlNl->value)
            ->assertCanSeeTableRecords([$dutch])
            ->assertCanNotSeeTableRecords([$belgian]);
    }

    #[Test]
    public function the_two_strips_narrow_each_other(): void
    {
        $dutchGuide = $this->plan(CoveKind::Guide, ['slug' => 'nl-guide', 'market' => Market::NlNl->value]);
        $dutchDaily = $this->plan(CoveKind::Daily, ['drop_date' => today()->addDay(), 'market' => Market::NlNl->value]);
        $belgianGuide = $this->plan(CoveKind::Guide, ['slug' => 'be-guide']);

        $component = Livewire::actingAs($this->admin())
            ->test(ListCovePlans::class)
            ->set('activeTab', CoveKind::Guide->value)
            ->set('activeMarket', Market::NlNl->value)
            ->assertCanSeeTableRecords([$dutchGuide])
            ->assertCanNotSeeTableRecords([$dutchDaily, $belgianGuide]);

        // The badges have to agree with what a click produces. A kind count
        // that ignored the chosen market would be a number no view can show.
        $tabs = $component->instance()->getTabs();
        $this->assertSame('1', $tabs[CoveKind::Guide->value]->getBadge());
        $this->assertSame('1', $tabs[CoveKind::Daily->value]->getBadge());
    }

    #[Test]
    public function the_draft_button_fills_the_tab_you_are_standing_on(): void
    {
        Livewire::actingAs($this->admin())
            ->test(ListCovePlans::class)
            ->callAction('draft', [
                'kind' => CoveKind::Persona->value,
                'market' => Market::BeNl->value,
                'count' => 3,
                'withProducts' => false,
            ])
            ->assertHasNoActionErrors();

        $plans = CovePlan::query()->where('kind', CoveKind::Persona->value)->get();

        $this->assertCount(3, $plans);

        // Drafts, and attributed. A button that produced approved plans would
        // be a content farm with a nicer interface.
        foreach ($plans as $plan) {
            $this->assertSame('draft', $plan->status);
            $this->assertNotNull($plan->created_by);
        }
    }

    #[Test]
    public function the_kinds_with_no_source_are_not_offered(): void
    {
        $component = Livewire::actingAs($this->admin())->test(ListCovePlans::class);

        /*
         * Advice and Shop have no source a machine can read, so they are absent
         * from the form rather than present and always failing. An option that
         * never works is a worse answer than an option that is not there.
         */
        // It still gets a tab: you have to be able to see the advice articles
        // somebody wrote, whether or not a machine can suggest one.
        $this->assertArrayHasKey(CoveKind::Advice->value, $component->instance()->getTabs());

        $component->callAction('draft', [
            'kind' => CoveKind::Advice->value,
            'market' => Market::BeNl->value,
            'count' => 2,
            'withProducts' => false,
        ])->assertHasActionErrors(['kind']);

        $this->assertSame(0, CovePlan::query()->count());
    }

    /** @param array<string, mixed> $attributes */
    private function plan(CoveKind $kind, array $attributes = []): CovePlan
    {
        return CovePlan::create([
            'market' => Market::BeNl->value,
            'kind' => $kind->value,
            'title' => 'Iets',
            'status' => 'draft',
            ...$attributes,
        ]);
    }

    private function admin(): User
    {
        $user = User::create([
            'name' => 'Test',
            'email' => 'admin@example.test',
            'password' => 'password-for-testing',
        ]);

        // is_admin is deliberately not mass-assignable: no request payload can
        // grant admin, and there is no self-service path to the panel.
        $user->forceFill(['is_admin' => true])->save();

        return $user;
    }
}
