<?php

declare(strict_types=1);

namespace App\Filament\Resources\CovePlans\Pages;

use App\Filament\Resources\CovePlans\CovePlanResource;
use Filament\Resources\Pages\ListRecords;

class ListCovePlans extends ListRecords
{
    protected static string $resource = CovePlanResource::class;

    // Create lives in the table header, next to the calendar it adds to.
    protected function getHeaderActions(): array
    {
        return [];
    }
}
