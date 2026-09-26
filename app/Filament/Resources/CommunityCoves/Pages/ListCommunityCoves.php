<?php

declare(strict_types=1);

namespace App\Filament\Resources\CommunityCoves\Pages;

use App\Filament\Resources\CommunityCoves\CommunityCoveResource;
use Filament\Resources\Pages\ListRecords;

class ListCommunityCoves extends ListRecords
{
    protected static string $resource = CommunityCoveResource::class;

    /** Nothing is created here: every row is a list its owner published. */
    protected function getHeaderActions(): array
    {
        return [];
    }
}
