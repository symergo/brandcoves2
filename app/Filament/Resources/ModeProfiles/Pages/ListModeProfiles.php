<?php

declare(strict_types=1);

namespace App\Filament\Resources\ModeProfiles\Pages;

use App\Filament\Resources\ModeProfiles\ModeProfileResource;
use Filament\Resources\Pages\ListRecords;

class ListModeProfiles extends ListRecords
{
    protected static string $resource = ModeProfileResource::class;

    // Create and the cache flush both live in the table's header actions, so
    // that the "Apply now" button sits next to the rows it applies.
    protected function getHeaderActions(): array
    {
        return [];
    }
}
