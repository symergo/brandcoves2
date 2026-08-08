<?php

declare(strict_types=1);

namespace App\Filament\Resources\DailyEditions\Pages;

use App\Filament\Resources\DailyEditions\DailyEditionResource;
use Filament\Resources\Pages\ListRecords;

class ListDailyEditions extends ListRecords
{
    protected static string $resource = DailyEditionResource::class;

    // The build action lives in the table header, next to the rows it produces.
    protected function getHeaderActions(): array
    {
        return [];
    }
}
