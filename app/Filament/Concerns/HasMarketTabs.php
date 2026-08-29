<?php

declare(strict_types=1);

namespace App\Filament\Concerns;

use App\Enums\Market;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\RenderHook;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Filament\View\PanelsRenderHook;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Url;

/**
 * A second row of tabs, one per market, under the kind tabs.
 *
 * Kind and market are the two axes an editor actually navigates by, and they are
 * independent: "what guides exist" and "what exists for Belgium" are both real
 * questions, and so is their intersection. Filament gives a list page one tab
 * strip, bound to `$activeTab`, so this adds a second one bound to its own
 * property and stacks it beneath — the page's `content()` schema is the only
 * place both can be composed.
 *
 * Why tabs rather than the market dropdown filter they replace: a filter is
 * something you have to think to apply, and it cannot carry the count that makes
 * an empty market obvious. "This market has no seasonal plans" is the fact worth
 * seeing without clicking, and it is the fact a dropdown hides. The dropdown was
 * removed rather than left alongside, because two controls on one axis can
 * disagree — tab on `be-nl`, filter on `be-fr` — and an empty table is then a
 * puzzle rather than an answer.
 *
 * The two strips cross-filter each other's badges: standing on Netherlands, the
 * kind counts are Dutch counts. A badge that ignored the other axis would be a
 * number that no click can ever reproduce.
 *
 * Every market, not just the published ones. An editor's job includes building
 * the market that has not opened yet — see App\Enums\Market::published().
 */
trait HasMarketTabs
{
    /**
     * Its own query-string key, so a filtered view survives being linked.
     *
     * `?tab=guide&market=nl-nl` is a shareable address for "the Dutch guides",
     * which is the thing an editor pastes into a message.
     */
    #[Url(as: 'market')]
    public ?string $activeMarket = null;

    public function mount(): void
    {
        parent::mount();

        // Without this the strip renders with nothing highlighted: the "all"
        // tab is only active when the property literally says `all`, and a null
        // matches no tab at all. Mirrors HasTabs::loadDefaultActiveTab().
        $this->activeMarket ??= 'all';
    }

    public function updatedActiveMarket(): void
    {
        // Page 4 of the Belgian plans is not page 4 of the Dutch ones, and
        // landing on an empty page reads as "this market has nothing".
        $this->resetPage();
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            $this->getTabsContentComponent(),
            $this->getMarketTabsContentComponent(),
            RenderHook::make(PanelsRenderHook::RESOURCE_PAGES_LIST_RECORDS_TABLE_BEFORE),
            EmbeddedTable::make(),
            RenderHook::make(PanelsRenderHook::RESOURCE_PAGES_LIST_RECORDS_TABLE_AFTER),
        ]);
    }

    /** @return array<string, Tab> */
    public function getMarketTabs(): array
    {
        $tabs = ['all' => Tab::make('All markets')];

        foreach (Market::cases() as $market) {
            $tabs[$market->value] = Tab::make($market->label())
                // Counted within the kind tab you are standing on.
                ->badge(fn (): int => $this->countInMarket($market));
        }

        return $tabs;
    }

    public function getMarketTabsContentComponent(): Component
    {
        return Tabs::make()
            ->key('marketTabs')
            ->livewireProperty('activeMarket')
            // Contained, where the kind strip is not: two identical strips
            // stacked read as one wrapped strip. The boxed treatment says
            // "second axis" without needing a label, which this renders only as
            // an aria-label anyway.
            ->contained()
            ->tabs($this->getMarketTabs());
    }

    /**
     * Narrow a query to the chosen market.
     *
     * Public because the kind tabs' badges need it too — their counts are wrong
     * the moment a market is chosen and they ignore it.
     *
     * @param  Builder<covariant \Illuminate\Database\Eloquent\Model>  $query
     * @return Builder<covariant \Illuminate\Database\Eloquent\Model>
     */
    public function scopeToActiveMarket(Builder $query): Builder
    {
        $market = Market::tryFrom((string) $this->activeMarket);

        return $market === null ? $query : $query->where('market', $market->value);
    }

    /**
     * @param  Builder<covariant \Illuminate\Database\Eloquent\Model>  $query
     * @return Builder<covariant \Illuminate\Database\Eloquent\Model>
     */
    protected function modifyQueryWithActiveTab(Builder $query, bool $isResolvingRecord = false): Builder
    {
        $query = parent::modifyQueryWithActiveTab($query, $isResolvingRecord);

        // Not when resolving a record: an edit or delete action arrives with an
        // id, and scoping that lookup to the tab you happen to be on turns a
        // stale tab into a 404 on a row that is right there on the screen.
        return $isResolvingRecord ? $query : $this->scopeToActiveMarket($query);
    }

    /** How many rows this market holds, within the kind currently shown. */
    protected function countInMarket(Market $market): int
    {
        return parent::modifyQueryWithActiveTab(static::getResource()::getEloquentQuery())
            ->where('market', $market->value)
            ->count();
    }
}
