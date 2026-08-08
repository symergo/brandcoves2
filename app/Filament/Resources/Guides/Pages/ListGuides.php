<?php

declare(strict_types=1);

namespace App\Filament\Resources\Guides\Pages;

use App\Filament\Resources\Guides\GuideResource;
use Filament\Resources\Pages\ListRecords;

class ListGuides extends ListRecords
{
    protected static string $resource = GuideResource::class;

    // No create action: guides are built from search demand, and a hand-made
    // one would have no source_volume to justify it.
    protected function getHeaderActions(): array
    {
        return [];
    }
}
