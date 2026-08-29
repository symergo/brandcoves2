<?php

declare(strict_types=1);

namespace App\Filament\Resources\CoveEditorials\Pages;

use App\Enums\CoveKind;
use App\Filament\Concerns\HasMarketTabs;
use App\Filament\Resources\CoveEditorials\CoveEditorialResource;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Support\Str;

/**
 * One tab per kind, one tab per market, and an "All" on each that means it.
 *
 * The tabs are the whole reason this screen replaced two. "What is live" is a
 * question about every kind at once; "why has no guide published this month" is
 * a question about one. A single filtered table answers both, and two separate
 * navigation entries answered neither.
 *
 * Market is the second axis and gets its own strip, because it is the other
 * question asked of this table every day — "is the Dutch site actually being
 * filled" — and it crosses the first rather than nesting under it. See
 * App\Filament\Concerns\HasMarketTabs.
 *
 * No create action. A Cove is not typed into existence here — it is planned in
 * the Cove planner and built from that plan, which is what guarantees every
 * published page has a record of what it was for.
 */
class ListCoveEditorials extends ListRecords
{
    use HasMarketTabs;

    protected static string $resource = CoveEditorialResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }

    /** @return array<string, Tab> */
    public function getTabs(): array
    {
        $tabs = ['all' => Tab::make('All')];

        foreach (CoveKind::cases() as $kind) {
            $tabs[$kind->value] = Tab::make(Str::plural($kind->label()))
                ->modifyQueryUsing(fn ($query) => $query->where('kind', $kind->value))
                // The count is the point on the empty ones: a market with no
                // seasonal Coves is a fact you want on the tab, not one you
                // discover by clicking through to an empty table. Counted
                // inside the chosen market, or the number belongs to a view
                // that no click reproduces.
                ->badge(fn () => $this->scopeToActiveMarket(static::getResource()::getEloquentQuery())
                    ->where('kind', $kind->value)
                    ->count());
        }

        return $tabs;
    }
}
