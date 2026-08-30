<?php

declare(strict_types=1);

namespace App\Filament\Resources\Feeds\Pages;

use App\Filament\Pages\DiscoverAwinFeeds;
use App\Filament\Resources\Feeds\FeedResource;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;

class ListFeeds extends ListRecords
{
    protected static string $resource = FeedResource::class;

    protected function getHeaderActions(): array
    {
        return [
            $this->discoverAction(),
            CreateAction::make(),
        ];
    }

    /**
     * Go and look at what Awin has.
     *
     * ## This used to be a modal, and the modal was the problem
     *
     * It asked for advertiser names in a text box, comma separated. That works
     * for whoever wrote the allowlist and is a wall for anybody else: you had to
     * already know a shop was on Awin, and to spell it the way Awin spells it —
     * "Vanden Borre BE" one month and something else the next. Getting it wrong
     * returned "nothing matched", which is indistinguishable from "we are not
     * joined to them".
     *
     * Nothing on that screen ever showed what was actually on offer, so the real
     * answer to "which shops can we add" stayed an SSH session and
     * `bc:awin-feeds`.
     *
     * {@see DiscoverAwinFeeds} lists every feed the accounts are joined to, with
     * the market each maps to and whether it is already registered, and a search
     * that narrows the list. Picking rows beats guessing names.
     */
    private function discoverAction(): Action
    {
        return Action::make('discover')
            ->label('Discover feeds')
            ->icon(Heroicon::OutlinedMagnifyingGlass)
            ->color('gray')
            ->url(DiscoverAwinFeeds::getUrl());
    }
}
