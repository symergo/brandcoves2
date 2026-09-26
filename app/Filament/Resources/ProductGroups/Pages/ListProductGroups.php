<?php

declare(strict_types=1);

namespace App\Filament\Resources\ProductGroups\Pages;

use App\Filament\Pages\MatchReview;
use App\Filament\Resources\ProductGroups\ProductGroupResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;

class ListProductGroups extends ListRecords
{
    protected static string $resource = ProductGroupResource::class;

    /** Products come from grouping; the one thing to do from here is review. */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('review')
                ->label('Match review')
                ->icon(Heroicon::OutlinedArrowsRightLeft)
                ->url(fn () => MatchReview::getUrl()),
        ];
    }
}
