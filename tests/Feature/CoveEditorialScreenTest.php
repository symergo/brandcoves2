<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\CoveKind;
use App\Enums\Market;
use App\Enums\PublishStatus;
use App\Filament\Resources\CoveEditorials\Pages\ListCoveEditorials;
use App\Models\DailyPickSet;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * What is live, sliced by kind and by market.
 *
 * The two strips are the whole navigation of this screen — there is no create
 * action and nothing else to click — so a strip that filtered nothing would
 * leave an editor scrolling one undifferentiated table and concluding the
 * Dutch site has no guides because they are ten pages down.
 */
class CoveEditorialScreenTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function the_kind_and_market_strips_narrow_each_other(): void
    {
        $dutchGuide = $this->cove(CoveKind::Guide, Market::NlNl, 'nl-guide');
        $belgianGuide = $this->cove(CoveKind::Guide, Market::BeNl, 'be-guide');
        $dutchPersona = $this->cove(CoveKind::Persona, Market::NlNl, 'nl-persona');

        $component = Livewire::actingAs($this->admin())->test(ListCoveEditorials::class);

        // Every market gets one, including the unpublished ones — a market is
        // built before it opens, and it is built on this screen.
        $this->assertSame(
            ['all', ...Market::values()],
            array_keys($component->instance()->getMarketTabs()),
        );

        $component->set('activeMarket', Market::NlNl->value)
            ->assertCanSeeTableRecords([$dutchGuide, $dutchPersona])
            ->assertCanNotSeeTableRecords([$belgianGuide])
            ->set('activeTab', CoveKind::Guide->value)
            ->assertCanSeeTableRecords([$dutchGuide])
            ->assertCanNotSeeTableRecords([$dutchPersona, $belgianGuide]);
    }

    private function cove(CoveKind $kind, Market $market, string $slug): DailyPickSet
    {
        return DailyPickSet::create([
            'market' => $market->value,
            'kind' => $kind->value,
            'drop_date' => null,
            'slug' => $slug,
            'theme_title' => $slug,
            'theme_slug' => $slug,
            'status' => PublishStatus::Published->value,
            'published_at' => now(),
        ]);
    }

    private function admin(): User
    {
        $user = User::create([
            'name' => 'Test',
            'email' => 'admin@example.test',
            'password' => 'password-for-testing',
        ]);

        // Not mass-assignable on purpose: no request payload grants admin.
        $user->forceFill(['is_admin' => true])->save();

        return $user;
    }
}
